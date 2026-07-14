<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Plugin\Log\Loggable;

class LogReturnTrackingNumber
{
    use Loggable;

    /**
     * Safety restriction for the initial live test.
     * No return data is read for any other order.
     */
    private const TEST_ORDER_ID = 468574;

    public function execute(EventProceduresTriggered $event)
    {
        $order = $event->getOrder();

        if ($order === null || empty($order->id)) {
            return;
        }

        if ((int) $order->id !== self::TEST_ORDER_ID) {
            return;
        }

        $this->getLogger(__METHOD__)->info(
            'ReturnTrackingToDeliveryNote::minimalBuildTestSuccessful',
            [
                'result' => 'Minimaler Plugin-Test wurde für Auftrag 468574 ausgeführt.',
                'orderId' => (int) $order->id
            ]
        );
    }
}
