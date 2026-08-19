<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named bundle of default per-user settings -- "child", "guest", "work" --
 * an operator applies when creating a service user to pre-fill the common
 * fields in one click. Deliberately decoupled from ServiceUser at runtime:
 * applying a template only seeds the create form (see
 * App\Filament\Resources\Services\Schemas\ServiceUserForm::formStateFromTemplate),
 * it stores no link and changing a template never touches users already
 * created from it. Every column mirrors the matching service_users column in
 * name, type and canonical unit so the copy is trivial.
 */
class ServiceUserTemplate extends Model
{
    protected $fillable = [
        'name',
        'logging_override',
        'labels',
        'dns_override',
        'rate_limit_kbps',
        'data_limit_bytes',
        'access_days',
        'access_start_minute',
        'access_end_minute',
    ];

    protected function casts(): array
    {
        return [
            'logging_override' => 'boolean',
            'labels' => 'array',
            'dns_override' => 'array',
            'rate_limit_kbps' => 'integer',
            'data_limit_bytes' => 'integer',
            'access_days' => 'array',
            'access_start_minute' => 'integer',
            'access_end_minute' => 'integer',
        ];
    }
}
