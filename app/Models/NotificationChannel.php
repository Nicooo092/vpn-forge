<?php

namespace App\Models;

use App\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * One configured destination for alerts. The `config` shape depends on `type`:
 *   discord  -> { webhook_url }
 *   telegram -> { bot_token, chat_id }
 *   ntfy     -> { server, topic }
 *   email    -> { to }
 */
class NotificationChannel extends Model
{
    protected $fillable = [
        'name',
        'type',
        'config',
        'events',
        'enabled',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'events' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Whether this channel wants a given event. An empty subscription means
     * "everything", so a channel added without picking events still gets them.
     */
    public function subscribesTo(NotificationEvent $event): bool
    {
        return blank($this->events) || in_array($event->value, $this->events, true);
    }
}
