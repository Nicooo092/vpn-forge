<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\ConnectionLogsTable;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageConnectionLogs extends ManageRelatedRecords
{
    protected static string $resource = ServiceResource::class;

    protected static string $relationship = 'connectionLogs';

    protected static ?string $title = 'Connections';

    public static function getNavigationLabel(): string
    {
        return __('Connections');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-signal';
    }

    public function table(Table $table): Table
    {
        return ConnectionLogsTable::configure($table);
    }
}
