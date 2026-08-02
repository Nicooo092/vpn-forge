<?php

namespace App\Filament\Pages;

use App\Models\AuditEvent;
use App\Models\Service;
use App\Models\ServiceUser;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ChangeHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.change-history';

    protected static ?int $navigationSort = 85;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-clock';
    }

    public static function getNavigationLabel(): string
    {
        return __('Change history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditEvent::query()->with('user'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime()
                    ->since()
                    ->tooltip(fn (AuditEvent $record) => $record->created_at?->toDayDateTimeString())
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('By'))
                    // Background jobs run with nobody signed in, and saying
                    // so is more honest than pinning it on whoever happened
                    // to be logged in last.
                    ->placeholder(__('automatic'))
                    ->searchable(),
                TextColumn::make('auditable_type')
                    ->label(__('What'))
                    ->formatStateUsing(fn (string $state) => class_basename($state))
                    ->badge(),
                TextColumn::make('subject')
                    ->label(__('Which'))
                    ->placeholder('--')
                    ->searchable(),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('summary')
                    ->label(__('Change'))
                    ->state(fn (AuditEvent $record) => $record->summary())
                    ->placeholder('--')
                    ->limit(70)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')->options([
                    'created' => __('Created'),
                    'updated' => __('Updated'),
                    'deleted' => __('Deleted'),
                ]),
                SelectFilter::make('auditable_type')
                    ->label(__('Type'))
                    ->options([
                        Service::class => __('Service'),
                        ServiceUser::class => __('User'),
                    ]),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->schema(fn (AuditEvent $record) => [
                        TextEntry::make('detail')
                            ->label(__('Recorded change'))
                            ->state(json_encode($record->change_set, JSON_PRETTY_PRINT))
                            ->fontFamily('mono'),
                    ])
                    ->modalSubmitAction(false),
            ])
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading(__('Nothing recorded yet'))
            ->emptyStateDescription(__('Edits to services and users appear here. Telemetry the poller writes on its own is deliberately left out, and key material is never recorded.'))
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }
}
