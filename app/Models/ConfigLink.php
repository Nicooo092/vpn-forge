<?php

namespace App\Models;

use App\Enums\ServiceUserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A public, tokenised link that lets one person collect their own client
 * config once, so the admin never has to send key material over email or chat.
 *
 * Only the SHA-256 of the token is stored; the plaintext exists solely in the
 * URL, which is shown once at creation and cannot be recovered afterwards --
 * the same discipline as a personal access token. A leaked database therefore
 * hands out nothing usable.
 */
class ConfigLink extends Model
{
    protected $fillable = [
        'service_user_id',
        'token_hash',
        'expires_at',
        'max_downloads',
        'downloads',
        'first_used_at',
        'last_used_at',
        'last_ip',
        'revoked_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'first_used_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function serviceUser(): BelongsTo
    {
        return $this->belongsTo(ServiceUser::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Links that could still be opened: not cancelled, not past expiry, uses
     * left. (Does not consider the owning user's status -- isUsable() is the
     * per-request authority on that; this is for showing and bulk-cancelling
     * outstanding links.)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereColumn('downloads', '<', 'max_downloads');
    }

    /**
     * Mints a link and returns both the saved model and the plaintext token to
     * put in the URL -- the only moment the token is ever available in the
     * clear.
     *
     * @return array{0: self, 1: string}
     */
    public static function mintFor(
        ServiceUser $user,
        ?Carbon $expiresAt,
        int $maxDownloads = 1,
        ?int $createdBy = null,
    ): array {
        // 48 url-safe chars -> ~285 bits of entropy, far past anything that
        // could be guessed even without the rate limit on the public route.
        $token = Str::random(48);

        $link = static::create([
            'service_user_id' => $user->id,
            'token_hash' => static::hash($token),
            'expires_at' => $expiresAt,
            'max_downloads' => $maxDownloads,
            'created_by' => $createdBy,
        ]);

        return [$link, $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The live link matching a plaintext token, or null if none matches. Does
     * not check usability -- the caller decides whether to peek (landing page)
     * or consume (reveal), and isUsable() reports why a found link is dead.
     */
    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', static::hash($token))->first();
    }

    /**
     * A link is only good while it is not revoked, not past its expiry, has
     * uses left, and belongs to a user who is still active -- a suspended or
     * revoked user's config must not be collectable, however valid the link
     * itself looks.
     */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->downloads >= $this->max_downloads) {
            return false;
        }

        return $this->serviceUser->status === ServiceUserStatus::Active;
    }

    /**
     * Records one reveal against the link. Called only from the POST reveal
     * path, never the peek, so a chat client pre-fetching the URL cannot burn
     * the link before the recipient opens it.
     */
    public function markUsed(?string $ip): void
    {
        $this->forceFill([
            'downloads' => $this->downloads + 1,
            'first_used_at' => $this->first_used_at ?? now(),
            'last_used_at' => now(),
            'last_ip' => $ip,
        ])->save();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->downloads >= $this->max_downloads;
    }
}
