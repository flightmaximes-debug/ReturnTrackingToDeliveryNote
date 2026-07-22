<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class LogReturnTrackingNumber
{
    use Loggable;

    private const TEST_ORDER_ID = 468574;

    public function execute(
        EventProceduresTriggered $event,
        ReturnsRepositoryContract $returnsRepository
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

        foreach ($returns as $return) {
            $this->getLogger(__METHOD__)->error(
                'ReturnTrackingToDeliveryNote::returnTrackingRead',
                [
                    'orderId' => self::TEST_ORDER_ID,
                    'returnId' => $return->id,
                    'returnsOrderId' => $return->returnsOrderId,
                    'providerId' => $return->providerId,
                    'externalNumber' => $return->externalNumber,
                    'fileName' => $return->fileName,
                    'createdAt' => $return->createdAt
                ]
            );
        }
    }
}
