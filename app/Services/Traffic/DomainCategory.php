<?php

namespace App\Services\Traffic;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Buckets a resolved hostname into a broad category, so the traffic a user
 * generates reads as "mostly streaming and social" rather than a flat list of
 * 400 hostnames. Purely local -- a curated suffix/keyword map, no external
 * lookup -- so it works offline and leaks nothing.
 *
 * Matching is by registrable-domain suffix first, then keyword, so
 * "i.ytimg.com" and "youtube-ui.l.google.com" both land under Streaming.
 */
enum DomainCategory: string implements HasColor, HasLabel
{
    case Advertising = 'advertising';
    case Social = 'social';
    case Streaming = 'streaming';
    case Shopping = 'shopping';
    case News = 'news';
    case Gaming = 'gaming';
    case Adult = 'adult';
    case Ai = 'ai';
    case Cloud = 'cloud';
    case Email = 'email';
    case Developer = 'developer';
    case Finance = 'finance';
    case Messaging = 'messaging';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Advertising => 'Ads & tracking',
            self::Social => 'Social',
            self::Streaming => 'Streaming',
            self::Shopping => 'Shopping',
            self::News => 'News',
            self::Gaming => 'Gaming',
            self::Adult => 'Adult',
            self::Ai => 'AI',
            self::Cloud => 'Cloud & CDN',
            self::Email => 'Email',
            self::Developer => 'Developer',
            self::Finance => 'Finance',
            self::Messaging => 'Messaging',
            self::Other => 'Other',
        };
    }

    public function getColor(): string|array
    {
        // Only Filament's built-in palette (danger/warning/success/info/
        // primary/gray), so every badge resolves without registering a colour.
        return match ($this) {
            self::Advertising => 'danger',
            self::Adult => 'danger',
            self::Shopping => 'warning',
            self::Finance => 'warning',
            self::Gaming => 'success',
            self::Developer => 'success',
            self::Social => 'info',
            self::Ai => 'info',
            self::Email => 'info',
            self::Messaging => 'info',
            self::Streaming => 'primary',
            self::News => 'gray',
            self::Cloud => 'gray',
            self::Other => 'gray',
        };
    }

    /**
     * Domain suffixes checked first (most specific signal). Keyed by category.
     *
     * @var array<string, list<string>>
     */
    private const SUFFIXES = [
        'advertising' => [
            'doubleclick.net', 'googlesyndication.com', 'googleadservices.com', 'google-analytics.com',
            'googletagmanager.com', 'adnxs.com', 'adsrvr.org', 'scorecardresearch.com', 'criteo.com',
            'taboola.com', 'outbrain.com', 'moatads.com', 'amazon-adsystem.com', 'rubiconproject.com',
            'pubmatic.com', 'casalemedia.com', 'branch.io', 'appsflyer.com', 'adjust.com', 'bugsnag.com',
            'sentry.io', 'segment.com', 'mixpanel.com', 'hotjar.com', 'onetrust.com', 'quantserve.com',
        ],
        'social' => [
            'facebook.com', 'fbcdn.net', 'instagram.com', 'cdninstagram.com', 'twitter.com', 'x.com',
            'twimg.com', 'tiktok.com', 'tiktokcdn.com', 'snapchat.com', 'sc-cdn.net', 'linkedin.com',
            'licdn.com', 'pinterest.com', 'pinimg.com', 'reddit.com', 'redditstatic.com', 'redd.it',
            'threads.net', 'tumblr.com', 'bsky.app',
        ],
        'streaming' => [
            'youtube.com', 'youtu.be', 'ytimg.com', 'googlevideo.com', 'netflix.com', 'nflxvideo.net',
            'nflximg.net', 'twitch.tv', 'ttvnw.net', 'jtvnw.net', 'spotify.com', 'scdn.co', 'primevideo.com',
            'disneyplus.com', 'dssott.com', 'hbomax.com', 'max.com', 'dailymotion.com', 'vimeo.com',
            'soundcloud.com', 'deezer.com', 'crunchyroll.com', 'plex.tv', 'wwe.com', 'molotov.tv',
        ],
        'shopping' => [
            'amazon.com', 'amazon.fr', 'media-amazon.com', 'ssl-images-amazon.com', 'ebay.com', 'ebayimg.com',
            'aliexpress.com', 'alicdn.com', 'cdiscount.com', 'fnac.com', 'shopify.com', 'shopifycdn.com',
            'etsy.com', 'leboncoin.fr', 'vinted.fr', 'vinted.com', 'zalando.com', 'temu.com', 'shein.com',
        ],
        'news' => [
            'lemonde.fr', 'lefigaro.fr', 'liberation.fr', 'bbc.co.uk', 'bbc.com', 'cnn.com', 'nytimes.com',
            'theguardian.com', 'reuters.com', 'apnews.com', 'francetvinfo.fr', '20minutes.fr', 'ouest-france.fr',
            'medium.com',
        ],
        'gaming' => [
            'steampowered.com', 'steamstatic.com', 'steamcommunity.com', 'epicgames.com', 'unrealengine.com',
            'riotgames.com', 'leagueoflegends.com', 'battle.net', 'blizzard.com', 'ea.com', 'ubisoft.com',
            'ubi.com', 'minecraft.net', 'mojang.com', 'roblox.com', 'rbxcdn.com', 'playstation.com',
            'xboxlive.com', 'nintendo.net', 'twitchcdn.net', 'curseforge.com', 'discordapp.com',
        ],
        'adult' => [
            'pornhub.com', 'phncdn.com', 'xvideos.com', 'xnxx.com', 'xhamster.com', 'redtube.com',
            'youporn.com', 'onlyfans.com', 'chaturbate.com', 'stripchat.com', 'trafficjunky.net',
        ],
        'ai' => [
            'openai.com', 'chatgpt.com', 'oaistatic.com', 'anthropic.com', 'claude.ai', 'gemini.google.com',
            'bard.google.com', 'perplexity.ai', 'midjourney.com', 'huggingface.co', 'copilot.microsoft.com',
            'x.ai', 'mistral.ai',
        ],
        'cloud' => [
            'cloudflare.com', 'cloudflare.net', 'cloudfront.net', 'amazonaws.com', 'akamai.net', 'akamaized.net',
            'fastly.net', 'azureedge.net', 'azure.com', 'windows.net', 'gstatic.com', 'googleapis.com',
            'googleusercontent.com', 'bunny.net', 'jsdelivr.net', 'unpkg.com', 'cloudinary.com', 'digitaloceanspaces.com',
        ],
        'email' => [
            'mail.google.com', 'gmail.com', 'googlemail.com', 'outlook.com', 'office365.com', 'hotmail.com',
            'live.com', 'protonmail.com', 'proton.me', 'yahoo.com', 'yahoomail.com', 'icloud.com', 'me.com',
            'zoho.com', 'mailchimp.com', 'sendgrid.net',
        ],
        'developer' => [
            'github.com', 'githubusercontent.com', 'githubassets.com', 'gitlab.com', 'bitbucket.org',
            'stackoverflow.com', 'npmjs.org', 'npmjs.com', 'pypi.org', 'docker.com', 'docker.io', 'packagist.org',
            'composer.getcomposer.org', 'getcomposer.org', 'nuget.org', 'gradle.org', 'maven.org', 'jetbrains.com',
            'vercel.app', 'netlify.app', 'readthedocs.io',
        ],
        'finance' => [
            'paypal.com', 'paypalobjects.com', 'stripe.com', 'stripe.network', 'revolut.com', 'wise.com',
            'coinbase.com', 'binance.com', 'kraken.com', 'boursorama.com', 'creditagricole.fr', 'labanquepostale.fr',
            'lcl.fr', 'bnpparibas.net', 'societegenerale.fr',
        ],
        'messaging' => [
            'whatsapp.com', 'whatsapp.net', 'telegram.org', 't.me', 'telegram.me', 'discord.com', 'discord.gg',
            'discordapp.net', 'signal.org', 'messenger.com', 'slack.com', 'slack-edge.com', 'zoom.us', 'teams.microsoft.com',
        ],
    ];

    /**
     * Keyword fallbacks, checked when no suffix matched. Loose substring match
     * against the whole hostname.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        'advertising' => ['analytics', 'tracker', 'tracking', 'telemetry', 'metric', 'pixel', 'adservice', 'ads.'],
        'cloud' => ['cdn', 'static', 'edge', 'assets'],
        'streaming' => ['video', 'stream'],
    ];

    public static function for(?string $host): self
    {
        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return self::Other;
        }

        foreach (self::SUFFIXES as $category => $suffixes) {
            foreach ($suffixes as $suffix) {
                $suffix = trim($suffix);
                if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                    return self::from($category);
                }
            }
        }

        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($host, $keyword)) {
                    return self::from($category);
                }
            }
        }

        return self::Other;
    }
}
