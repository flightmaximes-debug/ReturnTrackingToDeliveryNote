<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Property\Contracts\OrderPropertyRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsRepositoryContract;
use Plenty\Modules\Order\Transaction\Contracts\OrderItemTransactionRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class LogReturnTrackingNumber
{
    use Loggable;

    // The transaction fallback changes data belonging to a completed goods issue.
    // Keep it restricted until the Plenty order search has been verified manually.
    private const TRANSACTION_FALLBACK_TEST_ORDER_ID = 87312;

    public function execute(
        EventProceduresTriggered $event,
        ReturnsRepositoryContract $returnsRepository,
        OrderRepositoryContract $orderRepository,
        OrderPropertyRepositoryContract $orderPropertyRepository,
        OrderItemTransactionRepositoryContract $transactionRepository,
        AuthHelper $authHelper
    ) {
        $order = $event->getOrder();

        if ($order === null || (int) $order->id <= 0) {
            return;
        }

        $orderId = (int) $order->id;

        $paginatedReturns = $returnsRepository->getOrderReturns(
            $orderId,
            [],
            1,
            50,
            'id',
            'desc'
        );

        $returns = $paginatedReturns->getResult();

        if (count($returns) === 0) {
            $this->getLogger(__METHOD__)->error(
                'ReturnTrackingToDeliveryNote::noReturnFound',
                ['orderId' => $orderId]
            );
            return;
        }

        // The list is sorted descending. Only the newest return label is relevant.
        $return = $returns[0];
        $trackingNumber = trim((string) $return->externalNumber);

        if ($trackingNumber === '') {
            $this->getLogger(__METHOD__)->error(
                'ReturnTrackingToDeliveryNote::emptyReturnTrackingNumber',
                [
                    'orderId' => $orderId,
                    'returnId' => $return->id,
                ]
            );
            return;
        }

        $currentOrder = $orderRepository->findById($orderId, ['properties']);
        $existingProperty = $this->findExternalDeliveryNumberProperty($currentOrder);
        if ($existingProperty === null) {
            $existingProperty = $this->findFirstProperty(
                $orderPropertyRepository->findByOrderId(
                    $orderId,
                    OrderPropertyType::EXTERNAL_DELIVERY_NUMBER
                )
            );
        }

        $alreadySaved = $existingProperty !== null
            && (string) $existingProperty->value === $trackingNumber;
        $usedCompatibilityFallback = false;
        $compatibilityWriteResult = null;
        $fallbackOrderUpdateResult = null;

        $orderUpdateResult = $authHelper->processUnguarded(
            function () use ($orderRepository, $orderId, $trackingNumber) {
                return $orderRepository->update(
                    $orderId,
                    [
                        'properties' => [
                            [
                                'typeId' => OrderPropertyType::EXTERNAL_DELIVERY_NUMBER,
                                'value' => $trackingNumber
                            ]
                        ]
                    ]
                );
            }
        );

        // Some Plenty systems do not return legacy order properties on the current
        // order model. Read the property repository directly for a reliable check.
        $verifiedProperty = $this->findFirstProperty(
            $orderPropertyRepository->findByOrderId(
                $orderId,
                OrderPropertyType::EXTERNAL_DELIVERY_NUMBER
            )
        );

        if ($verifiedProperty === null || (string) $verifiedProperty->value !== $trackingNumber) {
            $usedCompatibilityFallback = true;
            $propertyData = [
                'orderId' => $orderId,
                'typeId' => OrderPropertyType::EXTERNAL_DELIVERY_NUMBER,
                'value' => $trackingNumber
            ];

            $compatibilityWriteResult = $authHelper->processUnguarded(
                function () use ($orderPropertyRepository, $propertyData, $verifiedProperty) {
                    if ($verifiedProperty === null) {
                        return $orderPropertyRepository->create($propertyData);
                    }

                    return $orderPropertyRepository->update(
                        $propertyData,
                        (int) $verifiedProperty->id
                    );
                }
            );

            // Trigger the regular order update path once more so Plenty can refresh
            // all downstream order data, including the order search.
            $fallbackOrderUpdateResult = $authHelper->processUnguarded(
                function () use ($orderRepository, $orderId, $trackingNumber) {
                    return $orderRepository->update(
                        $orderId,
                        [
                            'properties' => [
                                [
                                    'typeId' => OrderPropertyType::EXTERNAL_DELIVERY_NUMBER,
                                    'value' => $trackingNumber
                                ]
                            ]
                        ]
                    );
                }
            );

            $verifiedProperty = $this->findFirstProperty(
                $orderPropertyRepository->findByOrderId(
                    $orderId,
                    OrderPropertyType::EXTERNAL_DELIVERY_NUMBER
                )
            );
        }

        if ($verifiedProperty === null || (string) $verifiedProperty->value !== $trackingNumber) {
            $transactionFallback = $this->updateBookedOutgoingTransactions(
                $orderId,
                $trackingNumber,
                $order,
                $orderRepository,
                $transactionRepository,
                $authHelper
            );

            if ($transactionFallback['verified'] === true) {
                $this->getLogger(__METHOD__)->error(
                    'ReturnTrackingToDeliveryNote::transactionDeliveryNoteNumberSavedForTest',
                    [
                        'orderId' => $orderId,
                        'returnId' => $return->id,
                        'externalDeliveryNumber' => $trackingNumber,
                        'testMode' => true,
                        'transactionFallback' => $transactionFallback,
                        'fileName' => $return->fileName
                    ]
                );
                return;
            }

            $propertyTypeDiagnostic = $this->getPropertyTypeDiagnostic($orderPropertyRepository);
            $orderPropertiesDiagnostic = $this->getOrderPropertiesDiagnostic(
                $orderPropertyRepository,
                $orderId
            );

            $this->getLogger(__METHOD__)->error(
                'ReturnTrackingToDeliveryNote::externalDeliveryNumberVerificationFailed',
                [
                    'orderId' => $orderId,
                    'returnId' => $return->id,
                    'expectedExternalDeliveryNumber' => $trackingNumber,
                    'storedExternalDeliveryNumber' => $verifiedProperty === null
                        ? null
                        : (string) $verifiedProperty->value,
                    'usedCompatibilityFallback' => $usedCompatibilityFallback,
                    'propertyTypeId' => OrderPropertyType::EXTERNAL_DELIVERY_NUMBER,
                    'propertyTypeDiagnostic' => $propertyTypeDiagnostic,
                    'firstOrderUpdateResult' => $this->describeOrderUpdateResult($orderUpdateResult),
                    'compatibilityWriteResult' => $this->describeProperty($compatibilityWriteResult),
                    'fallbackOrderUpdateResult' => $this->describeOrderUpdateResult(
                        $fallbackOrderUpdateResult
                    ),
                    'orderPropertiesDiagnostic' => $orderPropertiesDiagnostic,
                    'transactionFallback' => $transactionFallback,
                    'fileName' => $return->fileName
                ]
            );
            return;
        }

        $this->getLogger(__METHOD__)->error(
            $alreadySaved
                ? 'ReturnTrackingToDeliveryNote::externalDeliveryNumberAlreadySaved'
                : 'ReturnTrackingToDeliveryNote::externalDeliveryNumberSaved',
            [
                'orderId' => $orderId,
                'returnId' => $return->id,
                'externalDeliveryNumber' => $trackingNumber,
                'previousValue' => $existingProperty === null ? null : $existingProperty->value,
                'verifiedPropertyId' => $verifiedProperty->id,
                'usedCompatibilityFallback' => $usedCompatibilityFallback,
                'fileName' => $return->fileName
            ]
        );
    }

    private function updateBookedOutgoingTransactions(
        $orderId,
        $trackingNumber,
        $eventOrder,
        OrderRepositoryContract $orderRepository,
        OrderItemTransactionRepositoryContract $transactionRepository,
        AuthHelper $authHelper
    ) {
        if ((int) $orderId !== self::TRANSACTION_FALLBACK_TEST_ORDER_ID) {
            return [
                'attempted' => false,
                'verified' => false,
                'reason' => 'restrictedToTestOrder',
                'testOrderId' => self::TRANSACTION_FALLBACK_TEST_ORDER_ID
            ];
        }

        try {
            $order = $orderRepository->findById($orderId, ['orderItems']);
            if ($order === null || $order->orderItems === null) {
                $order = $eventOrder;
            }

            if ($order === null || $order->orderItems === null) {
                return [
                    'attempted' => true,
                    'verified' => false,
                    'reason' => 'orderItemsUnavailable'
                ];
            }

            $eligibleTransactionIds = [];
            $previousValues = [];

            foreach ($order->orderItems as $orderItem) {
                if (!isset($orderItem->id) || (int) $orderItem->id <= 0) {
                    continue;
                }

                $transactions = $transactionRepository->list((int) $orderItem->id);
                foreach ($transactions as $transaction) {
                    $isBookedOutgoingTransaction = isset($transaction->id)
                        && (int) $transaction->id > 0
                        && isset($transaction->direction)
                        && (string) $transaction->direction === 'out'
                        && isset($transaction->status)
                        && (string) $transaction->status === 'regular'
                        && isset($transaction->receiptId)
                        && (int) $transaction->receiptId > 0;

                    if (!$isBookedOutgoingTransaction) {
                        continue;
                    }

                    $transactionId = (int) $transaction->id;
                    $eligibleTransactionIds[$transactionId] = (int) $orderItem->id;
                    $previousValues[$transactionId] = isset($transaction->deliveryNoteNumber)
                        ? (string) $transaction->deliveryNoteNumber
                        : null;
                }
            }

            if (count($eligibleTransactionIds) === 0) {
                return [
                    'attempted' => true,
                    'verified' => false,
                    'reason' => 'noBookedOutgoingTransactions'
                ];
            }

            foreach ($eligibleTransactionIds as $transactionId => $orderItemId) {
                $authHelper->processUnguarded(
                    function () use ($transactionRepository, $transactionId, $trackingNumber) {
                        return $transactionRepository->update(
                            $transactionId,
                            ['deliveryNoteNumber' => $trackingNumber]
                        );
                    }
                );
            }

            $verifiedTransactionIds = [];
            foreach (array_unique(array_values($eligibleTransactionIds)) as $orderItemId) {
                $transactions = $transactionRepository->list($orderItemId);
                foreach ($transactions as $transaction) {
                    if (!isset($transaction->id)) {
                        continue;
                    }

                    $transactionId = (int) $transaction->id;
                    if (!isset($eligibleTransactionIds[$transactionId])) {
                        continue;
                    }

                    if (isset($transaction->deliveryNoteNumber)
                        && (string) $transaction->deliveryNoteNumber === $trackingNumber
                    ) {
                        $verifiedTransactionIds[] = $transactionId;
                    }
                }
            }

            sort($verifiedTransactionIds);
            $expectedTransactionIds = array_map('intval', array_keys($eligibleTransactionIds));
            sort($expectedTransactionIds);

            return [
                'attempted' => true,
                'verified' => $verifiedTransactionIds === $expectedTransactionIds,
                'expectedTransactionIds' => $expectedTransactionIds,
                'verifiedTransactionIds' => $verifiedTransactionIds,
                'previousValues' => $previousValues
            ];
        } catch (\Throwable $exception) {
            return [
                'attempted' => true,
                'verified' => false,
                'reason' => 'exception',
                'errorClass' => get_class($exception),
                'errorMessage' => $exception->getMessage()
            ];
        }
    }

    private function findExternalDeliveryNumberProperty($order)
    {
        if ($order === null || $order->properties === null) {
            return null;
        }

        foreach ($order->properties as $property) {
            if ((int) $property->typeId === OrderPropertyType::EXTERNAL_DELIVERY_NUMBER) {
                return $property;
            }
        }

        return null;
    }

    private function findFirstProperty($properties)
    {
        if ($properties === null) {
            return null;
        }

        foreach ($properties as $property) {
            return $property;
        }

        return null;
    }

    private function getPropertyTypeDiagnostic(OrderPropertyRepositoryContract $repository)
    {
        try {
            $type = $repository->getType(
                OrderPropertyType::EXTERNAL_DELIVERY_NUMBER,
                ['de', 'en']
            );

            if ($type === null) {
                return ['found' => false];
            }

            return [
                'found' => true,
                'id' => isset($type->id) ? $type->id : null,
                'cast' => isset($type->cast) ? $type->cast : null
            ];
        } catch (\Throwable $exception) {
            return [
                'found' => false,
                'errorClass' => get_class($exception),
                'errorMessage' => $exception->getMessage()
            ];
        }
    }

    private function getOrderPropertiesDiagnostic(
        OrderPropertyRepositoryContract $repository,
        $orderId
    ) {
        $diagnostic = [];

        try {
            $properties = $repository->findByOrderId($orderId);

            foreach ($properties as $property) {
                $diagnostic[] = [
                    'id' => isset($property->id) ? $property->id : null,
                    'typeId' => isset($property->typeId) ? $property->typeId : null,
                    'isExpectedType' => isset($property->typeId)
                        && (int) $property->typeId === OrderPropertyType::EXTERNAL_DELIVERY_NUMBER
                ];
            }
        } catch (\Throwable $exception) {
            return [
                'readErrorClass' => get_class($exception),
                'readErrorMessage' => $exception->getMessage()
            ];
        }

        return $diagnostic;
    }

    private function describeProperty($property)
    {
        if ($property === null) {
            return null;
        }

        return [
            'id' => isset($property->id) ? $property->id : null,
            'orderId' => isset($property->orderId) ? $property->orderId : null,
            'typeId' => isset($property->typeId) ? $property->typeId : null,
            'value' => isset($property->value) ? (string) $property->value : null
        ];
    }

    private function describeOrderUpdateResult($order)
    {
        if ($order === null) {
            return null;
        }

        $property = $this->findExternalDeliveryNumberProperty($order);

        return [
            'orderId' => isset($order->id) ? $order->id : null,
            'containsExpectedProperty' => $property !== null,
            'expectedProperty' => $this->describeProperty($property)
        ];
    }
}
