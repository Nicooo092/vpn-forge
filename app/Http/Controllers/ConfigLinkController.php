<?php

namespace App\Http\Controllers;

use App\Enums\VpnProtocol;
use App\Models\ConfigLink;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The public side of a one-time config link. No authentication -- the token in
 * the URL is the credential -- so both entry points are rate limited (see the
 * route definitions) and every response carries no-store + noindex, because it
 * can put a client's private key on screen.
 *
 * Two steps on purpose. The landing page (GET) only confirms the link is live;
 * it reveals nothing and consumes nothing, so a chat client that pre-fetches
 * the URL to draw a preview cannot burn the link before the person opens it.
 * The reveal (POST, from the form on that page) is what actually spends a use
 * and shows the config -- something automated preview fetchers do not do.
 */
class ConfigLinkController extends Controller
{
    public function show(string $token): Response
    {
        $link = ConfigLink::findByToken($token);

        if ($link === null || ! $link->isUsable()) {
            return $this->deadLinkResponse($link);
        }

        $user = $link->serviceUser;

        return $this->harden(response()->view('config-link.show', [
            'token' => $token,
            'userName' => $user->name,
            'serviceName' => $user->service->name,
            'expiresAt' => $link->expires_at,
        ]));
    }

    public function reveal(Request $request, string $token): Response
    {
        $link = ConfigLink::findByToken($token);

        if ($link === null || ! $link->isUsable()) {
            return $this->deadLinkResponse($link);
        }

        $user = $link->serviceUser;
        $file = $user->clientConfigFile();

        // The privileged worker renders and caches the config; if it has not
        // done so yet, don't spend a use -- let them come back once it exists.
        if ($file === null) {
            return $this->harden(response()->view('config-link.pending', [
                'userName' => $user->name,
                'token' => $token,
            ]));
        }

        $link->markUsed($request->ip());

        $isWireGuard = $user->service->protocol === VpnProtocol::WireGuard;

        return $this->harden(response()->view('config-link.reveal', [
            'userName' => $user->name,
            'serviceName' => $user->service->name,
            'filename' => $file->filename,
            'contents' => $file->contents,
            'downloadUri' => 'data:application/octet-stream;base64,'.base64_encode($file->contents),
            'qrDataUri' => $isWireGuard ? $this->qrDataUri($file->contents) : null,
            'isWireGuard' => $isWireGuard,
        ]));
    }

    /**
     * A found-but-dead link says why (expired / used up / cancelled); an
     * unknown token says only that it is not valid, giving nothing away about
     * whether a token ever existed.
     */
    private function deadLinkResponse(?ConfigLink $link): Response
    {
        $reason = match (true) {
            $link === null => __('This link is not valid.'),
            $link->isRevoked() => __('This link has been cancelled.'),
            $link->isExpired() => __('This link has expired.'),
            $link->isExhausted() => __('This link has already been used.'),
            default => __('This link is no longer available.'),
        };

        return $this->harden(response()->view('config-link.expired', ['reason' => $reason]), status: 410);
    }

    /**
     * Never cache a page that can carry a private key, and keep it out of
     * search indexes. Referrer-Policy is already no-referrer via the meta tag
     * in the layout, so the token cannot leak that way either.
     */
    private function harden(Response $response, int $status = 200): Response
    {
        return $response
            ->setStatusCode($status)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function qrDataUri(string $contents): ?string
    {
        try {
            $writer = new Writer(new ImageRenderer(
                new RendererStyle(320),
                new SvgImageBackEnd,
            ));

            return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($contents));
        } catch (Throwable) {
            // A config too dense for a QR code (very long AllowedIPs, several
            // DNS servers): the download button still works, just no code.
            return null;
        }
    }
}
