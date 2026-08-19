<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Without this contract Filament only lets a user in when the app is
     * running in the local environment (see its Authenticate middleware), and
     * the installer left APP_ENV=local for exactly that reason -- meaning a
     * public install also ran with APP_DEBUG on, rendering full stack traces
     * to anyone who could trigger an error.
     *
     * Every row in this table is an operator: the panel has no public
     * registration, accounts exist only because someone with shell access
     * created one. So membership is the check.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Admin can change anything; Auditor is read-only (see UserRole). Any
     * gate/policy check goes through here, and Gate::before short-circuits
     * every ability to true for an admin (AppServiceProvider).
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isAuditor(): bool
    {
        return $this->role === UserRole::Auditor;
    }

    /**
     * How many admins exist. Used to refuse the last admin being demoted or
     * deleted, which would leave the panel with no one able to change anything.
     */
    public static function adminCount(): int
    {
        return static::query()->where('role', UserRole::Admin->value)->count();
    }

    public function isLastAdmin(): bool
    {
        return $this->isAdmin() && static::adminCount() <= 1;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'role' => UserRole::class,
            'password' => 'hashed',
            // two_factor_secret / two_factor_recovery_codes are deliberately NOT
            // cast: the TwoFactorAuthenticatable trait encrypts and decrypts them
            // itself (encrypt()/decrypt()), so an 'encrypted' cast here would
            // double-encrypt and break verification. They are hidden above and,
            // like every APP_KEY-encrypted column, decryptable only with the key
            // the backup archive carries.
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
