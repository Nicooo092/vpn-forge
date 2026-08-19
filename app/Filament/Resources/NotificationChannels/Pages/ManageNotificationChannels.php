<?php

namespace App\Filament\Resources\NotificationChannels\Pages;

use App\Filament\Resources\NotificationChannels\NotificationChannelResource;
use App\Models\NotificationChannel;
use App\Services\Notifications\Transports\TransportFactory;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Tables\Table;
use Throwable;

class ManageNotificationChannels extends ManageRecords
{
    protected static string $resource = NotificationChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add channel')),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordActions([
                // Sent inline (not queued) so the operator gets the pass/fail
                // right here rather than having to go and check the channel.
                Action::make('test')
                    ->label(__('Send test'))
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn () => auth()->user()?->isAdmin() ?? false)
                    ->action(function (NotificationChannel $record): void {
                        try {
                            app(TransportFactory::class)
                                ->for($record->type)
                                ->send($record->config ?? [], __('vpn-forge test'), __('This is a test alert from vpn-forge. If you can read it, the channel works.'));

                            $record->forceFill(['last_error' => null])->save();

                            Notification::make()->title(__('Test sent'))->success()->send();
                        } catch (Throwable $e) {
                            $record->forceFill(['last_error' => $e->getMessage()])->save();

                            Notification::make()->title(__('Test failed'))->body($e->getMessage())->danger()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
