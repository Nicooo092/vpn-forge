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

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $modelLabel = 'notification channel';

    protected static ?int $navigationSort = 84;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder('My phone, Ops channel, ...'),

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
                ->label('Discord webhook URL')
                ->url()
                ->required()
                ->visible(fn (Get $get) => $get('type') === 'discord')
                ->helperText('Discord → Server settings → Integrations → Webhooks → Copy URL.'),

            TextInput::make('config.bot_token')
                ->label('Bot token')
                ->required()
                // Validated here too, so a wrong-shape token is caught at save
                // rather than silently failing every later delivery.
                ->rule('regex:/^\d+:[A-Za-z0-9_-]+$/')
                ->visible(fn (Get $get) => $get('type') === 'telegram')
                ->helperText('From @BotFather, in the form 123456:ABC-DEF...'),
            TextInput::make('config.chat_id')
                ->label('Chat ID')
                ->required()
                ->visible(fn (Get $get) => $get('type') === 'telegram')
                ->helperText('Your numeric chat ID (message @userinfobot to find it).'),

            TextInput::make('config.server')
                ->label('ntfy server')
                ->url()
                ->default('https://ntfy.sh')
                ->required()
                // https only -- the topic in the path is a secret.
                ->rule('starts_with:https://')
                ->visible(fn (Get $get) => $get('type') === 'ntfy'),
            TextInput::make('config.topic')
                ->label('Topic')
                ->required()
                ->rule('regex:/^[A-Za-z0-9_-]{1,64}$/')
                ->visible(fn (Get $get) => $get('type') === 'ntfy')
                ->helperText('Letters, digits, - and _. Anyone who knows it can read it -- pick something unguessable.'),

            TextInput::make('config.to')
                ->label('Send to email')
                ->email()
                ->required()
                ->visible(fn (Get $get) => $get('type') === 'email')
                ->helperText('Needs SMTP configured in the server\'s .env, or the send will fail.'),

            CheckboxList::make('events')
                ->label('Notify me about')
                ->options(NotificationEvent::options())
                ->columns(2)
                ->helperText('Leave all unticked to receive every event.'),

            Toggle::make('enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('type')->badge(),
                ToggleColumn::make('enabled'),
                IconColumn::make('last_error')
                    ->label('OK')
                    ->boolean()
                    ->state(fn (NotificationChannel $record) => $record->last_error === null)
                    ->tooltip(fn (NotificationChannel $record) => $record->last_error),
            ])
            ->emptyStateHeading('No channels yet')
            ->emptyStateDescription('Add a Discord, Telegram, ntfy or email destination, then use "Send test" to check it.')
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNotificationChannels::route('/'),
        ];
    }
}
