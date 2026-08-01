<?php

namespace App\Filament\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use SensitiveParameter;

/**
 * Filament's own QR code comes out broken on a server without the imagick
 * extension, which is most of them.
 *
 * Without imagick, google2fa-qrcode falls back to BaconQrCode's SVG backend
 * and returns a finished data URI (QRCode\Bacon::getQRCodeInline). Filament's
 * fallback assumes it received raw SVG and base64-encodes the whole thing a
 * second time, so the browser decodes the image back to the literal text
 * "data:image/svg+xml;base64,..." and renders nothing.
 *
 * Rather than install imagick to dodge the branch, the URI is built here
 * directly -- the same way the WireGuard client-config QR already is.
 */
class SvgAppAuthentication extends AppAuthentication
{
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        /** @var HasAppAuthentication $user */
        $user = Filament::auth()->user();

        // The otpauth:// URI an authenticator app expects, rather than a
        // pre-rendered image, so the rendering stays ours.
        $otpauth = $this->google2FA->getQRCodeUrl(
            $this->getBrandName(),
            $this->getHolderName($user),
            $secret,
        );

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(280),
            new SvgImageBackEnd,
        ));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($otpauth));
    }
}
