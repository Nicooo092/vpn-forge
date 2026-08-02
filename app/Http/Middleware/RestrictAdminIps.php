<?php

namespace App\Http\Middleware;

use App\Models\AdminIpRule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the admin panel on the source network allowlist. Uses $request->ip(),
 * which resolves the real client address through the trusted-proxy config (so
 * behind Cloudflare it is the visitor, not the edge). With no rules configured
 * it is a pass-through, so the feature costs nothing until switched on.
 */
class RestrictAdminIps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AdminIpRule::allows($request->ip())) {
            abort(403, 'This network is not allowed to reach the panel.');
        }

        return $next($request);
    }
}
