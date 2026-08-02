<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * One allowed source network for the admin panel. The set of these is the
 * allowlist; empty means "no restriction".
 */
class AdminIpRule extends Model
{
    protected $fillable = ['label', 'cidr'];

    /**
     * Whether a given client address may reach the panel.
     *
     *  - No rules at all -> everyone (the feature is opt-in).
     *  - Loopback is always allowed, so a local or SSH-tunnelled operator can
     *    never be shut out even by a misconfigured list.
     *  - Otherwise the address must fall inside one of the rules.
     */
    public static function allows(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        $rules = static::query()->pluck('cidr')->all();

        if ($rules === []) {
            return true;
        }

        if (IpUtils::checkIp($ip, ['127.0.0.1', '::1'])) {
            return true;
        }

        return IpUtils::checkIp($ip, $rules);
    }
}
