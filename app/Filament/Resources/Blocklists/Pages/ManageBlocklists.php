<?php

namespace App\Filament\Resources\Blocklists\Pages;

use App\Filament\Resources\Blocklists\BlocklistResource;
use App\Jobs\Blocklist\RefreshBlocklists;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Table;

class ManageBlocklists extends ManageRecords
{
    protected static string $resource = BlocklistResource::class;

    protected ?string $pollingInterval = '10s';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add blocklist')
                ->after(fn () => $this->recompile('Blocklist added')),

            Action::make('refresh')
                ->label('Refresh now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->recompile('Refreshing blocklists')),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordActions([
                EditAction::make()->after(fn () => $this->recompile('Blocklist updated')),
                DeleteAction::make()->after(fn () => $this->recompile('Blocklist removed')),
            ]);
    }

    /**
     * Any change to the set of subscriptions dispatches a re-compile so it
     * takes effect without the operator having to remember to. The toggle
     * column below does the same when a list is switched on or off.
     */
    private function recompile(string $title): void
    {
        RefreshBlocklists::dispatch();

        Notification::make()
            ->title($title)
            ->body('Fetching and compiling in the background — the resolvers pick it up in a moment.')
            ->success()
            ->send();
    }
}
