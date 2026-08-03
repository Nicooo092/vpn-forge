<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Jobs\Vpn\ApplyServiceConfig;
use App\Jobs\Vpn\RemoveService;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    // A static property initializer cannot call __(), so the title has to be
    // built here or the heading stays English while the tab beside it does not.
    public function getTitle(): string|Htmlable
    {
        return __('Settings');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-cog-6-tooth';
    }

    protected function getHeaderActions(): array
    {
        return [
            // Deliberately not DeleteAction: deleting the row on its own
            // leaves the interface, NAT rules, systemd unit and dnsmasq
            // instance running on the host with nothing left in the panel
            // pointing at them. RemoveService tears all of that down first
            // and deletes the record itself once it has, which is also what
            // the services table does.
            Action::make('remove')
                ->label(__('Remove'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('This tears down the interface, NAT rules and DNS logging for this service, then deletes it. This cannot be undone.'))
                ->action(function (Service $record) {
                    RemoveService::dispatch($record);

                    Notification::make()
                        ->title(__('Removing service'))
                        ->body(__('Tearing down in the background.'))
                        ->success()
                        ->send();

                    return redirect(ServiceResource::getUrl('index'));
                }),
        ];
    }

    /**
     * config is a single JSON column, and the form binds one field per key it
     * knows about -- so Filament rebuilds the whole array from those fields
     * alone and silently drops anything else it holds. server_public_key is
     * generated once at provisioning and embedded in every client config, so
     * losing it on an unrelated edit (renaming the service, say) would leave
     * every config the panel hands out afterwards with an empty peer key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['config'] = array_merge(
            $this->record->config ?? [],
            $data['config'] ?? [],
        );

        return $data;
    }

    protected function afterSave(): void
    {
        ApplyServiceConfig::dispatch($this->record);
    }
}
