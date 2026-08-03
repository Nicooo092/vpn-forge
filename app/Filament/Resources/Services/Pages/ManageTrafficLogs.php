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

    public function getTitle(): string|Htmlable
    {
        return __('Traffic logs');
    }

    public static function getNavigationLabel(): string
    {
        return __('Traffic logs');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-document-magnifying-glass';
    }

    public function table(Table $table): Table
    {
        /** @var Service $service */
        $service = $this->getRecord();

        // The User column reads serviceUser.name on every row; without this the
        // page fires one query per log line rendered.
        return TrafficLogsTable::configure($table, $service)
            ->modifyQueryUsing(fn ($query) => $query->with('serviceUser'));
    }
}
