<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditEvent extends Model
{
    // Append-only: there is no updated_at because nothing ever edits one.
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'auditable_type',
        'auditable_id',
        'subject',
        'event',
        'change_set',
    ];

    protected function casts(): array
    {
        return [
            'change_set' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * "Renamed to X, allowance 5 GB -> 10 GB". Built from the recorded
     * before/after pairs so the list reads without opening anything.
     */
    public function summary(): string
    {
        if ($this->event !== 'updated') {
            return ucfirst($this->event);
        }

        return collect($this->change_set ?? [])
            ->map(function (array $pair, string $field) {
                $from = $this->readable($pair['from'] ?? null);
                $to = $this->readable($pair['to'] ?? null);

                return str_replace('_', ' ', $field).": {$from} -> {$to}";
            })
            ->implode(', ');
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => 'empty',
            $value === true => 'yes',
            $value === false => 'no',
            // json_encode, not implode: a changed value can itself be a nested
            // array (a service's whole `config` blob, whose entries include
            // arrays like push_routes and dns_upstreams). imploding that threw
            // "Array to string conversion" and 500'd the change-history page --
            // the tests only ever exercised scalar changes and missed it.
            is_array($value) => $value === []
                ? 'empty'
                : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'unreadable'),
            (string) $value === '' => 'empty',
            default => (string) $value,
        };
    }
}
