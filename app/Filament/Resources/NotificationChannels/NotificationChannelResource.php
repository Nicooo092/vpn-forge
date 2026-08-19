<?php

namespace App\Filament\Resources\NotificationChannels;

use App\Enums\NotificationEvent;
use App\Filament\Resources\NotificationChannels\Pages\ManageNotificationChannels;
use App\Models\NotificationChannel;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Out-of-band alert destinations. A channel is a Discord/Telegram/ntfy webhook
 * or an email address that receives the events it subscribes to.
 */
class NotificationChannelResource extends Resource
{
    protected static ?string $model = NotificationChannel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    public static function getNavigationLabel(): string
    {
        return __('Notifications');
    }

    // Methods rather than $modelLabel: a static property initializer cannot
    // call __(), and the default plural would run Str::plural() over the
    // translated singular, which is English-only pluralisation.
    public static function getModelLabel(): string
    {
        return __('notification channel');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notification channels');
    }

    protected static ?int $navigationSort = 84;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder(__('My phone, Ops channel, ...')),

            Select::make('type')
                ->required()
                ->live()
                ->options([
                    'discord' => 'Discord (webhook)',
                    'telegram' => 'Telegram (bot)',
                    'ntfy' => 'ntfy',
                    'email' => 'Email',
                ]),

            TextInput::make('config.webhook_url')
                ->label(__('Discord webhook URL'))
                ->url()
                ->required()
                ->visible(fn (Get $get) => $get('type') === 'discord')
                ->helperText(__('Discord → Server settings → Integrations → Webhooks → Copy URL.')),

            TextInput::make('config.bot_token')
                ->label(__('Bot token'))
                ->required()
                // Validated here too, so a wrong-shape token is caught at save
                // rather than silently failing every later delivery.
                ->rule('regex:/^\d+:[A-Za-z0-9_-]+$/')
                ->visible(fn (Get $get) => $get('type') === 'telegram')
                ->helperText(__('From @BotFather, in the form 123456:ABC-DEF...')),
            TextInput::make('config.chat_id')
                ->label(__('Chat ID'))
                ->required()
                ->visible(fn (Get $get) => $get('type') === 'telegram')
                ->helperText(__('Your numeric chat ID (message @userinfobot to find it).')),

            TextInput::make('config.server')
                ->label(__('ntfy server'))
                ->url()
                ->default('https://ntfy.sh')
                ->required()
                // https only -- the topic in the path is a secret.
                ->rule('starts_with:https://')
                ->visible(fn (Get $get) => $get('type') === 'ntfy'),
            TextInput::make('config.topic')
                ->label(__('Topic'))
                ->required()
                ->rule('regex:/^[A-Za-z0-9_-]{1,64}$/')
                ->visible(fn (Get $get) => $get('type') === 'ntfy')
                ->helperText(__('Letters, digits, - and _. Anyone who knows it can read it -- pick something unguessable.')),

            TextInput::make('config.to')
                ->label(__('Send to email'))
                ->email()
                ->required()
                ->visible(fn (Get $get) => $get('type') === 'email')
                ->helperText(__('Needs SMTP configured in the server\'s .env, or the send will fail.')),

            CheckboxList::make('events')
                ->label(__('Notify me about'))
                ->options(NotificationEvent::options())
                ->columns(2)
                ->helperText(__('Leave all unticked to receive every event.')),

            Toggle::make('enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('type')->badge(),
                // Inline toggles update the row through Livewire directly, which
                // the read-only policy does not gate -- disable it for auditors
                // so a read-only account cannot silence an alert channel.
                ToggleColumn::make('enabled')
                    ->disabled(fn () => ! (auth()->user()?->isAdmin() ?? false)),
                IconColumn::make('last_error')
                    ->label(__('OK'))
                    ->boolean()
                    ->state(fn (NotificationChannel $record) => $record->last_error === null)
                    ->tooltip(fn (NotificationChannel $record) => $record->last_error),
            ])
            // This list's own icon, not Filament's generic error glyph: having
            // no alert destination is a normal starting state.
            ->emptyStateIcon('heroicon-o-bell-alert')
            ->emptyStateHeading(__('No channels yet'))
            ->emptyStateDescription(__('Add a Discord, Telegram, ntfy or email destination, then use "Send test" to check it.'))
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNotificationChannels::route('/'),
        ];
    }
}
