<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use App\Enums\Transport;
use App\Enums\VpnProtocol;
use App\Services\Vpn\DriverFactory;
use App\Services\Vpn\VpnProtocolDriver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'protocol',
        'transport',
        'status',
        'interface_name',
        'subnet_cidr',
        'listen_port',
        'logging_enabled_default',
        'config',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'protocol' => VpnProtocol::class,
            'transport' => Transport::class,
            'status' => ServiceStatus::class,
            'logging_enabled_default' => 'boolean',
            'config' => 'array',
        ];
    }

    public function serviceUsers(): HasMany
    {
        return $this->hasMany(ServiceUser::class);
    }

    public function trafficLogs(): HasMany
    {
        return $this->hasMany(TrafficLog::class);
    }

    public function connectionLogs(): HasManyThrough
    {
        return $this->hasManyThrough(ConnectionLog::class, ServiceUser::class);
    }

    public function bandwidthSamples(): HasMany
    {
        return $this->hasMany(BandwidthSample::class);
    }

    public function driver(): VpnProtocolDriver
    {
        return app(DriverFactory::class)->make($this->protocol);
    }
}
