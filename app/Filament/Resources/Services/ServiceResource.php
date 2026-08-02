<?php

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\Pages\ManageConnectionLogs;
use App\Filament\Resources\Services\Pages\ManageServiceUsers;
use App\Filament\Resources\Services\Pages\ManageTrafficLogs;
use App\Filament\Resources\Services\Pages\ServiceReport;
use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Services');
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
    }

    /**
     * Each concern gets its own page rather than being stacked as relation
     * managers underneath the settings form. With a handful of services and
     * their users, connection history and traffic logs all on one screen,
     * there is no way to look at any one of them without wading past the
     * others -- and each of these tables is one somebody comes to the panel
     * specifically to read.
     *
     * @return array<class-string<Page>>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditService::class,
            ManageServiceUsers::class,
            ManageConnectionLogs::class,
            ManageTrafficLogs::class,
            ServiceReport::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
            'users' => ManageServiceUsers::route('/{record}/users'),
            'connections' => ManageConnectionLogs::route('/{record}/connections'),
            'traffic' => ManageTrafficLogs::route('/{record}/traffic'),
            'report' => ServiceReport::route('/{record}/report'),
        ];
    }
}
