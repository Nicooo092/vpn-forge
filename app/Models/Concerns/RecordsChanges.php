<?php

namespace App\Models\Concerns;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Records who changed what, so a service that stops working can be traced
 * back to the edit that did it rather than guessed at.
 *
 * Two things it deliberately does not record. Telemetry columns, because the
 * poller rewrites them every minute and would bury every real change under
 * thousands of handshake timestamps. And key material, because an audit
 * trail that copies private keys into a second table -- one nothing thinks
 * of as secret -- is worse than no audit trail at all.
 */
trait RecordsChanges
{
    /**
     * Never written to the log under any circumstances, on any model.
     *
     * @var list<string>
     */
    private static array $auditNeverRecord = [
        'wg_private_key',
        'wg_public_key',
        'wg_preshared_key',
        'rendered_client_config',
        'password',
        'remember_token',
    ];

    public static function bootRecordsChanges(): void
    {
        static::created(fn (Model $model) => $model->recordAuditEvent('created', []));

        static::updated(function (Model $model) {
            $changes = collect($model->getChanges())
                ->except([...self::$auditNeverRecord, ...$model->auditIgnored(), 'updated_at'])
                ->map(fn ($new, string $field) => [
                    'from' => $model->auditValue($model->getOriginal($field)),
                    'to' => $model->auditValue($new),
                ])
                ->all();

            if ($changes === []) {
                return; // Nothing worth a line.
            }

            $model->recordAuditEvent('updated', $changes);
        });

        static::deleted(fn (Model $model) => $model->recordAuditEvent('deleted', []));
    }

    public function auditEvents(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'auditable')->latest('created_at');
    }

    /**
     * Fields this model changes on its own, constantly, with no human
     * behind them.
     *
     * @return list<string>
     */
    protected function auditIgnored(): array
    {
        return [];
    }

    protected function auditSubject(): ?string
    {
        return $this->name ?? null;
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    private function recordAuditEvent(string $event, array $changes): void
    {
        AuditEvent::create([
            // Null for anything a queued job did: those run with nobody
            // signed in, and attributing them to a person would be a lie.
            'user_id' => auth()->id(),
            'auditable_type' => $this::class,
            'auditable_id' => $this->getKey(),
            'subject' => $this->auditSubject(),
            'event' => $event,
            'change_set' => $changes,
        ]);
    }

    private function auditValue(mixed $value): mixed
    {
        // Enums and dates would otherwise be stored as objects and come back
        // as unreadable arrays.
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            is_scalar($value) || is_array($value) || $value === null => $value,
            default => (string) $value,
        };
    }
}
