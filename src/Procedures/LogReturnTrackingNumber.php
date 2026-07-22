<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Property\Contracts\OrderPropertyRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class LogReturnTrackingNumber
{
    use Loggable;

    private const TEST_ORDER_ID = 468574;

    public function execute(
        EventProceduresTriggered $event,
        ReturnsRepositoryContract $returnsRepository,
        OrderPropertyRepositoryContract $orderPropertyRepository
    ) {
        $order = $event->getOrder();

        if ($order === null || (int) $order->id !== self::TEST_ORDER_ID) {
            return;
        }

        $paginatedReturns = $returnsRepository->getOrderReturns(
            self::TEST_ORDER_ID,
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
                ['orderId' => self::TEST_ORDER_ID]
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
                    'orderId' => self::TEST_ORDER_ID,
                    'returnId' => $return->id,
                ]
            );
            return;
        }

        $existingProperties = $orderPropertyRepository->findByOrderId(
            self::TEST_ORDER_ID,
            OrderPropertyType::EXTERNAL_DELIVERY_NUMBER
        );

        $existingProperty = null;
        foreach ($existingProperties as $property) {
            $existingProperty = $property;
            break;
        }

        if ($existingProperty !== null && (string) $existingProperty->value === $trackingNumber) {
            $this->getLogger(__METHOD__)->error(
                'ReturnTrackingToDeliveryNote::externalDeliveryNumberAlreadySaved',
                [
                    'orderId' => self::TEST_ORDER_ID,
                    'returnId' => $return->id,
                    'externalDeliveryNumber' => $trackingNumber
                ]
            );
            return;
        }

        $propertyData = [
            'orderId' => self::TEST_ORDER_ID,
            'typeId' => OrderPropertyType::EXTERNAL_DELIVERY_NUMBER,
            'value' => $trackingNumber
        ];

        if ($existingProperty === null) {
            $orderPropertyRepository->create($propertyData);
        } else {
            $orderPropertyRepository->update($propertyData, (int) $existingProperty->id);
        }

        $this->getLogger(__METHOD__)->error(
            'ReturnTrackingToDeliveryNote::externalDeliveryNumberSaved',
            [
                'orderId' => self::TEST_ORDER_ID,
                'returnId' => $return->id,
                'externalDeliveryNumber' => $trackingNumber,
                'previousValue' => $existingProperty === null ? null : $existingProperty->value,
                'fileName' => $return->fileName
            ]
        );
    }
}
