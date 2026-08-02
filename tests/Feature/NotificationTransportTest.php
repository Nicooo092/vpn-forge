<?php

namespace Tests\Feature;

use App\Services\Notifications\Transports\DiscordTransport;
use App\Services\Notifications\Transports\EmailTransport;
use App\Services\Notifications\Transports\NtfyTransport;
use App\Services\Notifications\Transports\TelegramTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class NotificationTransportTest extends TestCase
{
    public function test_discord_posts_the_message_to_the_webhook(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);

        (new DiscordTransport)->send(
            ['webhook_url' => 'https://discord.com/api/webhooks/1/abc'],
            'Title',
            'Body',
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/webhooks/1/abc'
            && str_contains($request['content'], 'Title')
            && str_contains($request['content'], 'Body'));
    }

    public function test_discord_refuses_a_non_https_webhook(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DiscordTransport)->send(['webhook_url' => 'http://evil.example'], 'T', 'B');
    }

    public function test_telegram_posts_to_send_message_with_the_chat_id(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new TelegramTransport)->send(['bot_token' => '123456:ABC-def_123', 'chat_id' => '42'], 'Title', 'Body');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123456:ABC-def_123/sendMessage')
            && $request['chat_id'] === '42'
            && str_contains($request['text'], 'Title'));
    }

    public function test_telegram_refuses_a_token_that_could_reshape_the_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TelegramTransport)->send(['bot_token' => 'abc/../evil', 'chat_id' => '42'], 'T', 'B');
    }

    public function test_ntfy_posts_to_the_topic_with_a_title_header(): void
    {
        Http::fake(['ntfy.sh/*' => Http::response('', 200)]);

        (new NtfyTransport)->send(['server' => 'https://ntfy.sh', 'topic' => 'my-secret-topic'], 'Title', 'Body');

        Http::assertSent(fn ($request) => $request->url() === 'https://ntfy.sh/my-secret-topic'
            && $request->hasHeader('Title', 'Title')
            && $request->body() === 'Body');
    }

    public function test_ntfy_refuses_a_topic_that_walks_the_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NtfyTransport)->send(['server' => 'https://ntfy.sh', 'topic' => 'a/../../admin'], 'T', 'B');
    }

    public function test_ntfy_refuses_a_cleartext_server(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NtfyTransport)->send(['server' => 'http://ntfy.example', 'topic' => 'topic'], 'T', 'B');
    }

    public function test_email_refuses_an_invalid_address(): void
    {
        Mail::fake();

        $this->expectException(InvalidArgumentException::class);
        (new EmailTransport)->send(['to' => 'not-an-email'], 'T', 'B');
    }

    public function test_email_sends_to_a_valid_address(): void
    {
        Mail::fake();
        $this->expectNotToPerformAssertions();

        // A valid address must pass the guard and reach the mailer without
        // throwing (the raw send is a no-op under Mail::fake).
        (new EmailTransport)->send(['to' => 'ops@example.com'], 'Title', 'Body');
    }
}
