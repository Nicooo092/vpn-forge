<?php

namespace App\Http\Controllers;

use App\Services\Metrics\PrometheusExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The Prometheus scrape endpoint. It sits outside the Filament panel and its
 * session auth -- a monitoring scraper has no cookie -- so the bearer token is
 * the only thing between an anonymous request and the panel's counts.
 *
 * Two deliberate behaviours: with no token configured the endpoint 404s, so an
 * install that never turned monitoring on discloses nothing (not even that the
 * route exists); with a token configured, the presented credential is compared
 * in constant time and anything else gets a bare 401. The body carries counts
 * and per-service up/down only -- never keys, addresses or user data.
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request, PrometheusExporter $exporter): Response
    {
        $configured = trim((string) config('vpnforge.metrics.token', ''));

        // Monitoring is opt-in: no token, no endpoint.
        if ($configured === '') {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        if (! $this->authorized($request, $configured)) {
            return response('', SymfonyResponse::HTTP_UNAUTHORIZED)
                ->header('WWW-Authenticate', 'Bearer')
                ->header('Cache-Control', 'no-store');
        }

        // render() drives a full health snapshot (several COUNT/SUM queries plus
        // /proc and disk reads). Share it across a burst of scrapes with a short
        // server-side cache -- the numbers barely move within it, and this keeps
        // a tight scrape interval off the database, exactly as the blocking
        // widgets already do. The no-store header is about the SCRAPER not
        // caching a credentialed body; it is unrelated to this internal reuse.
        $body = Cache::remember('vpnforge:metrics:render', 15, fn () => $exporter->render());

        return response($body, SymfonyResponse::HTTP_OK)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    private function authorized(Request $request, string $configured): bool
    {
        $presented = (string) ($request->bearerToken() ?? '');

        // hash_equals is constant-time; the emptiness guard keeps a blank
        // Authorization header from ever being compared.
        return $presented !== '' && hash_equals($configured, $presented);
    }
}
