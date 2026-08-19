<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectionLog extends Model
{
    protected $fillable = [
        'service_user_id',
        'connected_at',
        'disconnected_at',
        'peer_ip',
        'country_code',
        'country_name',
        'asn',
        'as_org',
        'bytes_in',
        'bytes_out',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'asn' => 'integer',
        ];
    }

    public function serviceUser(): BelongsTo
    {
        return $this->belongsTo(ServiceUser::class);
    }
}
