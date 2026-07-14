<?php

namespace ReturnTrackingToDeliveryNote\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use Throwable;

class LogReturnTrackingNumber
{
    use Loggable;

    /**
     * Safety restriction for the initial live test.
     * No return data is read for any other order.
     */
    private const TEST_ORDER_ID = 468574;

    private $returnsRepository;

    public function __construct(ReturnsRepositoryContract $returnsRepository)
    {
        $this->returnsRepository = $returnsRepository;
    }

    public function execute(EventProceduresTriggered $event)
    {
        $order = $event->getOrder();

        if ($order === null || empty($order->id)) {
            return;
        }

        if ((int) $order->id !== self::TEST_ORDER_ID) {
            return;
        }

        try {
            $trackingNumber = $this->findLatestTrackingNumber((int) $order->id);

            if ($trackingNumber === null) {
                $this->getLogger(__METHOD__)->warning(
                    'ReturnTrackingToDeliveryNote::trackingNumberMissing',
                    ['orderId' => (int) $order->id]
                );
                return;
            }

            $this->getLogger(__METHOD__)->info(
                'ReturnTrackingToDeliveryNote::trackingNumberDetected',
                [
                    'result' => sprintf(
                        'Retourensendungsnummer %s gehört zu Auftrag %d.',
                        $trackingNumber,
                        (int) $order->id
                    ),
                    'orderId' => (int) $order->id,
                    'returnTrackingNumber' => $trackingNumber
                ]
            );
        } catch (Throwable $throwable) {
            $this->getLogger(__METHOD__)->error(
                'ReturnTrackingToDeliveryNote::readFailed',
                [
                    'orderId' => (int) $order->id,
                    'message' => $throwable->getMessage()
                ]
            );
        }
    }

    private function findLatestTrackingNumber(int $orderId): ?string
    {
        $result = $this->returnsRepository->getOrderReturns(
            $orderId,
            [],
            1,
            50,
            'id',
            'desc'
        );

        $data = method_exists($result, 'toArray') ? $result->toArray() : (array) $result;
        $entries = $data['entries'] ?? $data['items'] ?? $data['data'] ?? [];

        foreach ($entries as $entry) {
            $return = is_object($entry) && method_exists($entry, 'toArray')
                ? $entry->toArray()
                : (array) $entry;

            foreach (['externalNumber', 'packageNumber', 'returnPackageNumber'] as $field) {
                $value = trim((string) ($return[$field] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

}
