<?php

namespace App\Filament\Resources\Services\Pages;

use App\Exceptions\InvalidServiceDefinitionException;
use App\Filament\Concerns\GuardsAdminActions;
use App\Filament\Resources\Services\ServiceResource;
use App\Jobs\Vpn\ProvisionService;
use App\Services\Vpn\ServiceDefinition;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListServices extends ListRecords
{
    use GuardsAdminActions;

    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // Create a service from a definition previously produced by the
            // per-row Export action. Admin-only: it is a mutating entry point,
            // so the closure re-checks with abortUnlessAdmin() rather than
            // trusting the hidden button (a crafted Livewire call would still
            // reach it otherwise).
            Action::make('import')
                ->label(__('Import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(static::adminOnly())
                ->modalSubmitActionLabel(__('Import'))
                ->schema([
                    Textarea::make('json')
                        ->label(__('Service definition JSON'))
                        ->required()
                        ->rows(12)
                        ->helperText(__('Paste a JSON file previously produced by Export. Secrets and keys are never included; fresh keys are generated on provisioning.')),
                ])
                ->action(function (array $data): void {
                    $this->abortUnlessAdmin();

                    try {
                        $service = app(ServiceDefinition::class)->createFromImport($data['json']);
                    } catch (InvalidServiceDefinitionException $e) {
                        Notification::make()
                            ->title(__('Import failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    ProvisionService::dispatch($service);

                    Notification::make()
                        ->title(__('Service imported'))
                        ->body(__('Provisioning :name in the background.', ['name' => $service->name]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
