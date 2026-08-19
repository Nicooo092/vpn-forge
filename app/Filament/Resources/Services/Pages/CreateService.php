<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Jobs\Vpn\ProvisionService;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    /**
     * Where the Clone action (ServicesTable) stashes the form state to
     * pre-fill. Session, not a query string, because the payload is a whole
     * nested config array.
     */
    public const CLONE_SESSION_KEY = 'vpnforge.clone_service_prefill';

    /**
     * A cloned service arrives with its whole form state staged in the
     * session. Fill from that once (advanced mode, so the suggested network
     * details are on screen and are not re-derived out from under us), then
     * let the normal create flow -- validation, afterCreate provisioning --
     * take over exactly as for a hand-entered service.
     */
    protected function fillForm(): void
    {
        $prefill = session()->pull(self::CLONE_SESSION_KEY);

        if (is_array($prefill)) {
            $this->form->fill($prefill);

            return;
        }

        parent::fillForm();
    }

    protected function afterCreate(): void
    {
        ProvisionService::dispatch($this->record);
    }
}
