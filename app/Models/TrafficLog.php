<?php

namespace App\Models;

use App\Enums\TrafficLogKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficLog extends Model
{
    // Written by the Go capture agent, not through Eloquent -- append-only,
    // no updated_at column exists on this table.
    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'service_user_id',
        'kind',
        'occurred_at',
        'source_ip',
        'host',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TrafficLogKind::class,
            'occurred_at' => 'datetime',
            'detail' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceUser(): BelongsTo
    {
        return $this->belongsTo(ServiceUser::class);
    }
}
