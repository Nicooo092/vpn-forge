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
            is_array($value) => $value === [] ? 'empty' : implode(', ', $value),
            (string) $value === '' => 'empty',
            default => (string) $value,
        };
    }
}
