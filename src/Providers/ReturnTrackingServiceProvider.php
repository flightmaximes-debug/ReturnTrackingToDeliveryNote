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
                'de' => 'TEST: Plugin-Build und Auftrags-ID im Log prüfen',
                'en' => 'TEST: Verify plugin build and order ID in log'
            ],
            LogReturnTrackingNumber::class . '@execute',
            ProcedureEntry::PROCEDURE_GROUP_RETURN
        );
    }
}
