<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
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
        OrderPropertyRepositoryContract $orderPropertyRepository
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

        $orderRepository->update(
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

            if ($verifiedProperty === null) {
                $orderPropertyRepository->create($propertyData);
            } else {
                $orderPropertyRepository->update($propertyData, (int) $verifiedProperty->id);
            }

            // Trigger the regular order update path once more so Plenty can refresh
            // all downstream order data, including the order search.
            $orderRepository->update(
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

            $verifiedProperty = $this->findFirstProperty(
                $orderPropertyRepository->findByOrderId(
                    $orderId,
                    OrderPropertyType::EXTERNAL_DELIVERY_NUMBER
                )
            );
        }

        if ($verifiedProperty === null || (string) $verifiedProperty->value !== $trackingNumber) {
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
}
