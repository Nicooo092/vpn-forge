<?php

namespace App\Filament\Resources\Blocklists;

use App\Filament\Resources\Blocklists\Pages\ManageBlocklists;
use App\Jobs\Blocklist\RefreshBlocklists;
use App\Models\Blocklist;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Subscriptions to public blocklists. They compile, on a schedule, into one
 * hosts file every opted-in service's resolver serves -- network-wide
 * ad/malware blocking that keeps itself current. A service can opt out in its
 * own settings.
 */
class BlocklistResource extends Resource
{
    protected static ?string $model = Blocklist::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    public static function getNavigationLabel(): string
    {
        return __('Blocklists');
    }

    protected static ?string $modelLabel = 'blocklist';

    protected static ?int $navigationSort = 82;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // A quick pick that fills the two fields below; not stored itself.
            Select::make('preset')
                ->label('Start from a known list')
                ->placeholder('Choose one, or fill in your own below')
                ->options(collect(Blocklist::presets())->map(fn ($p) => $p['name']))
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $preset = Blocklist::presets()[$state] ?? null;

                    if ($preset) {
                        $set('name', $preset['name']);
                        $set('url', $preset['url']);
                    }
                }),

            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('url')
                ->label('List URL')
                ->required()
                ->url()
                ->maxLength(2048)
                ->helperText('A hosts file, a plain domain list, or an AdGuard/adblock filter. Refreshed daily.'),

            Toggle::make('enabled')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Blocklist $record) => $record->url, position: 'below'),
                ToggleColumn::make('enabled')
                    // Switching a list on or off re-compiles so it applies
                    // without a separate "refresh" click.
                    ->afterStateUpdated(fn () => RefreshBlocklists::dispatch()),
                TextColumn::make('domain_count')
                    ->label('Domains')
                    ->numeric()
                    ->placeholder('not fetched yet')
                    ->sortable(),
                TextColumn::make('last_fetched_at')
                    ->label('Last updated')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),
                IconColumn::make('last_error')
                    ->label('OK')
                    ->boolean()
                    ->state(fn (Blocklist $record) => $record->last_error === null)
                    ->tooltip(fn (Blocklist $record) => $record->last_error),
            ])
            ->emptyStateHeading('No blocklists yet')
            ->emptyStateDescription('Add a subscription — start from a known list — and it is fetched, merged and served by every service that has blocklists enabled.')
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBlocklists::route('/'),
        ];
    }
}
