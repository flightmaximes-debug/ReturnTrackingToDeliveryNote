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
                'de' => 'TEST: Retouren-Sendungsnummer im Plugin-Log ausgeben',
                'en' => 'TEST: Write return tracking number to the plugin log'
            ],
            LogReturnTrackingNumber::class . '@execute',
            ProcedureEntry::PROCEDURE_GROUP_RETURN
        );
    }
}
