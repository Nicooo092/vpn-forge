<?php

namespace App\Filament\Resources\ServiceUserTemplates;

use App\Filament\Resources\ServiceUserTemplates\Pages\ManageServiceUserTemplates;
use App\Models\ServiceUserTemplate;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Manages the named profiles applied when adding a service user. Writes are
 * admin-only (ServiceUserTemplatePolicy + Gate::before); an Auditor sees the
 * list read-only. Values are stored and displayed in the same units the
 * ServiceUserForm uses (Mbit/s, GB, HH:MM) so applying a template is a copy.
 */
class ServiceUserTemplateResource extends Resource
{
    protected static ?string $model = ServiceUserTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 81;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('User templates');
    }

    // Methods rather than $modelLabel: a static property initializer cannot
    // call __(), and the default plural would run Str::plural() over the
    // translated singular, which is English-only pluralisation.
    public static function getModelLabel(): string
    {
        return __('user template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('user templates');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder(__('child, guest, work')),

            Select::make('logging_override')
                ->label(__('Logging'))
                ->options([
                    '' => __('Inherit from service'),
                    '1' => __('Force on'),
                    '0' => __('Force off'),
                ])
                ->default(''),

            TagsInput::make('labels')
                ->label(__('Labels'))
                ->placeholder(__('family, work, client-x')),

            TagsInput::make('dns_override')
                ->label(__('DNS for this user'))
                ->placeholder('1.1.1.1, 9.9.9.9')
                ->nestedRecursiveRules(['ipv4']),

            // Stored as kbit/s, shown in Mbit/s -- same conversion the user
            // form uses, so the copied value round-trips unchanged.
            TextInput::make('rate_limit_kbps')
                ->label(__('Speed limit (Mbit/s)'))
                ->numeric()
                ->minValue(0.1)
                ->step(0.1)
                ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1000, 2))
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (int) round((float) $state * 1000) : null),

            TextInput::make('data_limit_bytes')
                ->label(__('Data allowance (GB)'))
                ->numeric()
                ->minValue(0.1)
                ->step(0.1)
                ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1024 ** 3, 2))
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? (int) round((float) $state * 1024 ** 3) : null),

            CheckboxList::make('access_days')
                ->label(__('Access days'))
                ->options([
                    1 => __('Mon'),
                    2 => __('Tue'),
                    3 => __('Wed'),
                    4 => __('Thu'),
                    5 => __('Fri'),
                    6 => __('Sat'),
                    7 => __('Sun'),
                ])
                ->columns(7)
                ->gridDirection('row'),

            // Stored as minutes since midnight (0-1439); both or neither.
            TimePicker::make('access_start_minute')
                ->label(__('Access from'))
                ->seconds(false)
                ->requiredWith('access_end_minute')
                ->formatStateUsing(fn (?int $state) => self::minutesToTime($state))
                ->dehydrateStateUsing(fn (?string $state) => self::timeToMinutes($state)),

            TimePicker::make('access_end_minute')
                ->label(__('Access until'))
                ->seconds(false)
                ->requiredWith('access_start_minute')
                ->formatStateUsing(fn (?int $state) => self::minutesToTime($state))
                ->dehydrateStateUsing(fn (?string $state) => self::timeToMinutes($state)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('rate_limit_kbps')
                    ->label(__('Speed limit (Mbit/s)'))
                    ->state(fn (ServiceUserTemplate $record) => $record->rate_limit_kbps === null
                        ? null
                        : round($record->rate_limit_kbps / 1000, 2))
                    ->placeholder(__('unlimited')),
                TextColumn::make('data_limit_bytes')
                    ->label(__('Allowance'))
                    ->state(fn (ServiceUserTemplate $record) => $record->data_limit_bytes === null
                        ? null
                        : round($record->data_limit_bytes / 1024 ** 3, 2).' GB')
                    ->placeholder(__('unlimited')),
                TextColumn::make('labels')
                    ->label(__('Labels'))
                    ->badge()
                    ->separator(',')
                    ->placeholder('--'),
                IconColumn::make('logging_override')
                    ->label(__('Logging'))
                    ->boolean()
                    ->placeholder('--'),
            ])
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('No templates yet'))
            ->emptyStateDescription(__('Create a reusable profile — a name plus a set of default limits — to apply with one click when adding a user.'))
            ->defaultSort('name');
    }

    /**
     * Reshape submitted form data before it is saved: fold the logging select's
     * string tri-state back to boolean/null and store an empty tag/day set as
     * null (the column's absent state), mirroring ServiceUsersTable::normaliseUserData.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normaliseTemplateData(array $data): array
    {
        $data['logging_override'] = ($data['logging_override'] ?? '') === '' ? null : (bool) $data['logging_override'];

        if (array_key_exists('dns_override', $data)) {
            $data['dns_override'] = filled($data['dns_override']) ? array_values($data['dns_override']) : null;
        }

        if (array_key_exists('labels', $data)) {
            $data['labels'] = filled($data['labels']) ? array_values($data['labels']) : null;
        }

        if (array_key_exists('access_days', $data)) {
            $data['access_days'] = filled($data['access_days'])
                ? array_values(array_map('intval', $data['access_days']))
                : null;
        }

        return $data;
    }

    /**
     * The inverse for the edit form: the boolean column becomes the select's
     * '' / '1' / '0' tri-state so an existing template opens with the right
     * option selected.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateTemplateData(array $data): array
    {
        $data['logging_override'] = match ($data['logging_override'] ?? null) {
            true => '1',
            false => '0',
            default => '',
        };

        return $data;
    }

    private static function minutesToTime(?int $minute): ?string
    {
        return $minute === null ? null : sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private static function timeToMinutes(?string $state): ?int
    {
        if (! filled($state)) {
            return null;
        }

        $time = Carbon::parse($state);

        return $time->hour * 60 + $time->minute;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServiceUserTemplates::route('/'),
        ];
    }
}
