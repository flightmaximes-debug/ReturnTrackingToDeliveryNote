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
                'de' => 'Retouren-Sendungsnummer als externe Lieferscheinnummer speichern',
                'en' => 'Save return tracking number as external delivery number'
            ],
            LogReturnTrackingNumber::class . '@execute',
            ProcedureEntry::PROCEDURE_GROUP_RETURN
        );
    }
}
