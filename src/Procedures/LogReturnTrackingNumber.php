<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class LogReturnTrackingNumber
{
    use Loggable;

    public function execute(
        EventProceduresTriggered $event,
        ReturnsRepositoryContract $returnsRepository,
        OrderRepositoryContract $orderRepository
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

        $currentOrder = $orderRepository->findById($orderId);
        $existingProperty = $this->findExternalDeliveryNumberProperty($currentOrder);

        if ($existingProperty !== null && (string) $existingProperty->value === $trackingNumber) {
            $this->getLogger(__METHOD__)->error(
                'ReturnTrackingToDeliveryNote::externalDeliveryNumberAlreadySaved',
                [
                    'orderId' => $orderId,
                    'returnId' => $return->id,
                    'externalDeliveryNumber' => $trackingNumber
                ]
            );
            return;
        }

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

        // Load the order again. A success log is only written when Plenty confirms
        // that the value was persisted through the current order API.
        $verifiedOrder = $orderRepository->findById($orderId);
        $verifiedProperty = $this->findExternalDeliveryNumberProperty($verifiedOrder);

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
                    'fileName' => $return->fileName
                ]
            );
            return;
        }

        $this->getLogger(__METHOD__)->error(
            'ReturnTrackingToDeliveryNote::externalDeliveryNumberSaved',
            [
                'orderId' => $orderId,
                'returnId' => $return->id,
                'externalDeliveryNumber' => $trackingNumber,
                'previousValue' => $existingProperty === null ? null : $existingProperty->value,
                'verifiedPropertyId' => $verifiedProperty->id,
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
}
