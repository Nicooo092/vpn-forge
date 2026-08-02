<?php

namespace Tests\Feature;

use App\Enums\NotificationEvent;
use App\Jobs\Notifications\SendNotification;
use App\Models\NotificationChannel;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\Transports\TransportFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotifierTest extends TestCase
{
    use RefreshDatabase;

    private function channel(array $overrides = []): NotificationChannel
    {
        return NotificationChannel::create(array_merge([
            'name' => 'ch',
            'type' => 'ntfy',
            'config' => ['server' => 'https://ntfy.sh', 'topic' => 'topic'],
            'events' => [],
            'enabled' => true,
        ], $overrides));
    }

    public function test_it_fans_out_only_to_enabled_subscribed_channels(): void
    {
        Queue::fake();

        $all = $this->channel(['name' => 'all', 'events' => []]);
        $errorsOnly = $this->channel(['name' => 'err', 'events' => ['service_error']]);
        $backupsOnly = $this->channel(['name' => 'bak', 'events' => ['backup_failed']]);
        $disabled = $this->channel(['name' => 'off', 'events' => [], 'enabled' => false]);

        app(Notifier::class)->event(NotificationEvent::ServiceError, 'Title', 'Body');

        // Only the "all" and the service_error-subscribed channels.
        Queue::assertPushed(SendNotification::class, 2);
        Queue::assertPushed(SendNotification::class, fn ($job) => $job->channel->is($all));
        Queue::assertPushed(SendNotification::class, fn ($job) => $job->channel->is($errorsOnly));
        Queue::assertNotPushed(SendNotification::class, fn ($job) => $job->channel->is($backupsOnly) || $job->channel->is($disabled));
    }

    public function test_the_event_emoji_and_title_are_carried(): void
    {
        Queue::fake();
        $this->channel();

        app(Notifier::class)->event(NotificationEvent::BackupFailed, 'Backup failed', 'why');

        Queue::assertPushed(SendNotification::class, fn ($job) => str_contains($job->title, 'Backup failed'));
    }

    public function test_the_send_job_clears_last_error_on_success(): void
    {
        Http::fake(['ntfy.sh/*' => Http::response('', 200)]);
        $channel = $this->channel(['last_error' => 'a previous failure']);

        (new SendNotification($channel, 'T', 'B'))->handle(app(TransportFactory::class));

        $this->assertNull($channel->refresh()->last_error);
    }

    public function test_the_send_job_records_a_failure_without_throwing(): void
    {
        Http::fake(['ntfy.sh/*' => Http::response('nope', 500)]);
        $channel = $this->channel();

        // Must not throw -- a broken channel should never pile up in failed_jobs.
        (new SendNotification($channel, 'T', 'B'))->handle(app(TransportFactory::class));

        $error = $channel->refresh()->last_error;
        $this->assertNotNull($error);
        // Reduced to a status; the endpoint URL (which carries the secret) is
        // never stored.
        $this->assertStringContainsString('HTTP 500', $error);
        $this->assertStringNotContainsString('ntfy.sh', $error);
    }
}
