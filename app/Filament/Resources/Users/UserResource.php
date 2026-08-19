<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/**
 * Panel accounts. Everyone here is an operator with full access -- there is
 * no role system -- so the list doubles as the answer to "who can read the
 * traffic logs".
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    public static function getNavigationLabel(): string
    {
        return __('Admin accounts');
    }

    // Methods rather than $modelLabel: a static property initializer cannot
    // call __(), and the default plural would run Str::plural() over the
    // translated singular, which is English-only pluralisation.
    public static function getModelLabel(): string
    {
        return __('admin account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin accounts');
    }

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->label(__('Email address'))
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            Select::make('role')
                ->label(__('Role'))
                ->options(UserRole::options())
                ->default(UserRole::Admin->value)
                ->required()
                ->selectablePlaceholder(false)
                // The last admin cannot be demoted to auditor -- that would
                // leave the panel with no one able to change anything.
                ->disabled(fn (?User $record) => $record?->isLastAdmin() ?? false)
                ->dehydrated()
                ->helperText(__('Auditor can see everything, including the traffic logs, but cannot change anything.')),

            TextInput::make('password')
                ->password()
                ->revealable()
                ->confirmed()
                ->minLength(12)
                // Required when creating, optional when editing: leaving it
                // blank on an edit must not blank out the stored hash.
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                ->helperText(__('At least 12 characters. Leave blank when editing to keep the current password.')),

            TextInput::make('password_confirmation')
                ->label(__('Confirm password'))
                ->password()
                ->revealable()
                ->dehydrated(false)
                ->required(fn (string $operation) => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label(__('Email address'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->badge()
                    ->color(fn (UserRole $state) => $state === UserRole::Admin ? 'primary' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('Added'))
                    ->since()
                    ->sortable(),
            ])
            // The list's own icon, not Filament's generic error glyph: an
            // empty list here is a state, not a failure.
            ->emptyStateIcon('heroicon-o-user-circle')
            ->emptyStateHeading(__('No accounts'))
            ->emptyStateDescription(__('Everyone listed here signs in to this panel with full access, including the traffic logs.'))
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
