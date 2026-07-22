<?php

namespace ReturnTrackingToDeliveryNote\Providers;

use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Plugin\ServiceProvider;
use ReturnTrackingToDeliveryNote\Procedures\LogReturnTrackingNumber;

class ReturnTrackingServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot(EventProceduresService $eventProceduresService)
    {
        $eventProceduresService->registerProcedure(
            'ReturnTrackingToDeliveryNote',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'TEST: Retouren-Sendungsnummer als externe Lieferscheinnummer speichern (nur Auftrag 468574)',
                'en' => 'TEST: Save return tracking number as external delivery number (order 468574 only)'
            ],
            LogReturnTrackingNumber::class . '@execute',
            ProcedureEntry::PROCEDURE_GROUP_RETURN
        );
    }
}
