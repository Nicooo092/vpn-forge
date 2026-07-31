<?php

namespace App\Models;

use App\Enums\ServiceUserStatus;
use App\Services\Vpn\ClientConfigFile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'name',
        'status',
        'tunnel_ip',
        'wg_public_key',
        'wg_private_key',
        'wg_preshared_key',
        'openvpn_common_name',
        'certificate_serial',
        'issued_at',
        'revoked_at',
        'logging_override',
        'last_handshake_at',
        'last_connected_at',
        'last_seen_ip',
        'last_cumulative_bytes_in',
        'last_cumulative_bytes_out',
        'rendered_client_config',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceUserStatus::class,
            'logging_override' => 'boolean',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_handshake_at' => 'datetime',
            'last_connected_at' => 'datetime',
            // Key material is encrypted at rest (Eloquent, backed by
            // APP_KEY) so it can be re-downloaded later without needing to
            // regenerate keys/certs.
            'wg_private_key' => 'encrypted',
            'wg_preshared_key' => 'encrypted',
            'rendered_client_config' => 'encrypted',
        ];
    }

    protected $hidden = [
        'wg_private_key',
        'wg_preshared_key',
        'rendered_client_config',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function connectionLogs(): HasMany
    {
        return $this->hasMany(ConnectionLog::class);
    }

    public function trafficLogs(): HasMany
    {
        return $this->hasMany(TrafficLog::class);
    }

    /**
     * The logging state actually in effect, resolving the per-user override
     * against the parent service's default when the override is null.
     */
    public function loggingEffective(): bool
    {
        return $this->logging_override ?? $this->service->logging_enabled_default;
    }

    /**
     * Re-renders this user's downloadable client config via the protocol
     * driver and caches it. Only ever called from the privileged worker
     * (the driver needs filesystem access to /etc/wireguard or
     * /etc/openvpn/vpnforge, which the web process deliberately doesn't
     * have) -- addUser() does this once, applyServiceConfig() re-does it
     * for every user so a service-level change (DNS, AllowedIPs, endpoint
     * host, ...) doesn't leave stale configs behind.
     */
    public function refreshRenderedClientConfig(): void
    {
        $file = $this->service->driver()->buildClientConfig($this->service, $this);

        $this->forceFill([
            'rendered_client_config' => json_encode(['filename' => $file->filename, 'contents' => $file->contents]),
        ])->save();
    }

    public function clientConfigFile(): ?ClientConfigFile
    {
        if ($this->rendered_client_config === null) {
            return null;
        }

        $decoded = json_decode($this->rendered_client_config, true);

        return new ClientConfigFile($decoded['filename'], $decoded['contents']);
    }
}
