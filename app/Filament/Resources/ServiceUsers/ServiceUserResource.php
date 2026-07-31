<?php

namespace App\Filament\Resources\ServiceUsers;

use App\Filament\Resources\ServiceUsers\Pages\ViewServiceUser;
use App\Models\ServiceUser;
use Filament\Resources\Resource;

/**
 * Exists only to give one user a page of their own, reached from the users
 * table of a service. It is deliberately absent from the navigation: users
 * belong to a service, and browsing them detached from one is not useful.
 */
class ServiceUserResource extends Resource
{
    protected static ?string $model = ServiceUser::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewServiceUser::route('/{record}'),
        ];
    }
}
