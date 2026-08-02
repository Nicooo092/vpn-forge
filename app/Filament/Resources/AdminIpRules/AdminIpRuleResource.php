<?php

namespace App\Filament\Resources\AdminIpRules;

use App\Filament\Resources\AdminIpRules\Pages\ManageAdminIpRules;
use App\Models\AdminIpRule;
use BackedEnum;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The admin panel's source-network allowlist. Empty = reachable from anywhere;
 * add a rule and only listed networks (plus loopback) get in.
 */
class AdminIpRuleResource extends Resource
{
    protected static ?string $model = AdminIpRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    public static function getNavigationLabel(): string
    {
        return __('Panel access');
    }

    protected static ?string $modelLabel = 'allowed network';

    protected static ?int $navigationSort = 88;

    protected static ?string $recordTitleAttribute = 'cidr';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('cidr')
                ->label(__('IP or CIDR'))
                ->required()
                ->placeholder(__('203.0.113.4 or 203.0.113.0/24'))
                ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                    [$ip, $prefix] = array_pad(explode('/', (string) $value, 2), 2, null);

                    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                        $fail(__('Enter a valid IP address or CIDR range.'));

                        return;
                    }

                    if ($prefix !== null) {
                        $max = str_contains($ip, ':') ? 128 : 32;

                        if (! ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > $max) {
                            $fail(__('The prefix after / must be 0-:max.', ['max' => $max]));
                        }
                    }
                })
                ->helperText(__('A single address, or a range like 203.0.113.0/24. Your current address is :ip.', ['ip' => request()->ip()])),

            TextInput::make('label')
                ->maxLength(255)
                ->placeholder(__('Home, office, ...')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cidr')->label(__('IP / CIDR'))->weight('bold')->fontFamily('mono'),
                TextColumn::make('label')->placeholder('--'),
                TextColumn::make('created_at')->label(__('Added'))->since()->sortable(),
            ])
            ->emptyStateHeading(__('No restriction'))
            ->emptyStateDescription(__('The panel is reachable from any network. Add an address to restrict it -- loopback always stays allowed, and you can clear the list over SSH if you get locked out.'))
            ->defaultSort('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdminIpRules::route('/'),
        ];
    }
}
