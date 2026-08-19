<?php

namespace App\Enums;

/**
 * The things worth telling an operator about, out of band. Each channel
 * subscribes to some or all of them.
 */
enum NotificationEvent: string
{
    case ServiceError = 'service_error';
    case BackupFailed = 'backup_failed';
    case QuotaWarning = 'quota_warning';
    case UserSuspended = 'user_suspended';
    case DiskWarning = 'disk_warning';
    case NewLocation = 'new_location';
    case JobsFailed = 'jobs_failed';

    public function label(): string
    {
        return match ($this) {
            self::ServiceError => __('A service goes into error'),
            self::BackupFailed => __('A backup fails'),
            self::QuotaWarning => __('A user nears their data allowance'),
            self::UserSuspended => __('A user is auto-suspended'),
            self::DiskWarning => __('Disk space runs low'),
            self::NewLocation => __('A user connects from a new network'),
            self::JobsFailed => __('A background job fails'),
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::ServiceError => "\u{26A0}\u{FE0F}",   // warning
            self::BackupFailed => "\u{1F4BE}",           // floppy
            self::QuotaWarning => "\u{1F4CA}",           // bar chart
            self::UserSuspended => "\u{23F8}\u{FE0F}",   // pause
            self::DiskWarning => "\u{1F5C4}\u{FE0F}",    // file cabinet
            self::NewLocation => "\u{1F310}",            // globe
            self::JobsFailed => "\u{1F6A8}",             // rotating light
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
