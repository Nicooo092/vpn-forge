<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\Schemas\ServiceUserForm;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\ServiceUsersTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageServiceUsers extends ManageRelatedRecords
{
    protected static string $resource = ServiceResource::class;

    protected static string $relationship = 'serviceUsers';

    protected static ?string $title = 'Users';

    public static function getNavigationLabel(): string
    {
        return 'Users';
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-users';
    }

    public function form(Schema $schema): Schema
    {
        /** @var Service $service */
        $service = $this->getRecord();

        return ServiceUserForm::configure($schema, $service);
    }

    public function table(Table $table): Table
    {
        return ServiceUsersTable::configure($table);
    }
}
