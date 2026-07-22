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
                'de' => 'TEST: Retouren-Sendungsnummer für Auftrag 468574 auslesen',
                'en' => 'TEST: Read return tracking number for order 468574'
            ],
            LogReturnTrackingNumber::class . '@execute',
            ProcedureEntry::PROCEDURE_GROUP_RETURN
        );
    }
}
