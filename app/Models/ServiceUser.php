<?php

namespace App\Models;

use App\Enums\ServiceUserStatus;
use App\Models\Concerns\RecordsChanges;
use App\Services\Vpn\ClientConfigFile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceUser extends Model
{
    use HasFactory;
    use RecordsChanges;

    /**
     * Rewritten by the poller every minute for every connected user. Logging
     * these would bury every real change under thousands of lines.
     *
     * @return list<string>
     */
    protected function auditIgnored(): array
    {
        return [
            'last_handshake_at',
            'last_connected_at',
            'last_seen_ip',
            'last_cumulative_bytes_in',
            'last_cumulative_bytes_out',
            'certificate_serial',
            'issued_at',
        ];
    }

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
        'key_rotations',
        'issued_at',
        'revoked_at',
        'logging_override',
        'expires_at',
        'data_limit_bytes',
        'quota_started_at',
        'suspended_reason',
        'labels',
        'dns_override',
        'rate_limit_kbps',
        'access_days',
        'access_start_minute',
        'access_end_minute',
        'max_connections',
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
            'expires_at' => 'datetime',
            'quota_started_at' => 'datetime',
            'labels' => 'array',
            'dns_override' => 'array',
            'rate_limit_kbps' => 'integer',
            'access_days' => 'array',
            'access_start_minute' => 'integer',
            'access_end_minute' => 'integer',
            'max_connections' => 'integer',
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

    public function bandwidthSamples(): HasMany
    {
        return $this->hasMany(BandwidthSample::class);
    }

    public function configLinks(): HasMany
    {
        return $this->hasMany(ConfigLink::class);
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
     * Summed from the recorded deltas rather than read off the interface: the
     * kernel counters restart from zero every time the tunnel is brought back
     * up, so anything measured from them alone forgets the traffic that came
     * before the last restart.
     */
    public function dataUsedBytes(): int
    {
        return (int) $this->bandwidthSamples()
            ->when($this->quota_started_at, fn ($query) => $query->where('sampled_at', '>=', $this->quota_started_at))
            ->sum(DB::raw('bytes_in_delta + bytes_out_delta'));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether any time-of-day restriction is set. A day list, a time window, or
     * both -- with neither, access is unrestricted.
     */
    public function hasAccessSchedule(): bool
    {
        return filled($this->access_days)
            || ($this->access_start_minute !== null && $this->access_end_minute !== null);
    }

    /**
     * Whether the given moment (default now, in the app timezone) falls inside
     * this user's allowed window. A time window whose start is after its end is
     * read as spanning midnight (e.g. 22:00-06:00). With no schedule, always
     * true.
     */
    public function isWithinAccessWindow(?Carbon $now = null): bool
    {
        if (! $this->hasAccessSchedule()) {
            return true;
        }

        $now ??= Carbon::now(config('app.timezone'));

        // Normalise the stored days to ints -- a checkbox list may persist them
        // as strings -- so a strict comparison against isoWeekday() holds.
        if (filled($this->access_days)
            && ! in_array($now->isoWeekday(), array_map('intval', $this->access_days), true)) {
            return false;
        }

        // Equal bounds are treated as "all day", not a zero-width window: a
        // user who picks 00:00-00:00 expecting no time limit must not end up
        // permanently locked out. (The form also refuses equal times.)
        if ($this->access_start_minute !== null
            && $this->access_end_minute !== null
            && $this->access_start_minute !== $this->access_end_minute) {
            $minute = $now->hour * 60 + $now->minute;
            $start = $this->access_start_minute;
            $end = $this->access_end_minute;

            $within = $start < $end
                ? ($minute >= $start && $minute < $end)
                : ($minute >= $start || $minute < $end);

            if (! $within) {
                return false;
            }
        }

        return true;
    }

    /**
     * A short human-readable form of the schedule for the panel, or null when
     * there is none.
     */
    public function accessScheduleSummary(): ?string
    {
        if (! $this->hasAccessSchedule()) {
            return null;
        }

        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        $parts = [];

        $parts[] = filled($this->access_days)
            ? collect($this->access_days)->map(fn ($d) => $names[(int) $d] ?? $d)->implode(', ')
            : 'Every day';

        if ($this->access_start_minute !== null && $this->access_end_minute !== null) {
            $parts[] = $this->formatMinuteOfDay($this->access_start_minute).'-'.$this->formatMinuteOfDay($this->access_end_minute);
        }

        return implode(' · ', $parts);
    }

    private function formatMinuteOfDay(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    public function isOverDataLimit(): bool
    {
        return $this->data_limit_bytes !== null
            && $this->dataUsedBytes() >= $this->data_limit_bytes;
    }

    /**
     * Fraction of the allowance used, or null when there is no allowance.
     * Capped at 1 so a bar drawn from it cannot overflow.
     */
    public function dataUsageRatio(): ?float
    {
        if ($this->data_limit_bytes === null || $this->data_limit_bytes === 0) {
            return null;
        }

        return min(1.0, $this->dataUsedBytes() / $this->data_limit_bytes);
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
