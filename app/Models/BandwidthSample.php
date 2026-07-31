<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BandwidthSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'service_user_id',
        'sampled_at',
        'bytes_in_delta',
        'bytes_out_delta',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
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
