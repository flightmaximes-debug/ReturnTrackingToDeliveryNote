<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Property\Contracts\OrderPropertyRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class LogReturnTrackingNumber
{
    use Loggable;

    public function execute(
        EventProceduresTriggered $event,
        ReturnsRepositoryContract $returnsRepository,
        OrderRepositoryContract $orderRepository,
        OrderPropertyRepositoryContract $orderPropertyRepository,
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
