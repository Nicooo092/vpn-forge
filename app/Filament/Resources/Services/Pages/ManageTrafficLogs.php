<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Services\Tables\TrafficLogsTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ManageTrafficLogs extends ManageRelatedRecords
{
    protected static string $resource = ServiceResource::class;

    protected static string $relationship = 'trafficLogs';

    protected static ?string $title = 'Traffic logs';

    public static function getNavigationLabel(): string
    {
        return 'Traffic logs';
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-document-magnifying-glass';
    }

    public function table(Table $table): Table
    {
        /** @var Service $service */
        $service = $this->getRecord();

        return TrafficLogsTable::configure($table, $service);
    }
}
