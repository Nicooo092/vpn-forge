<?php

namespace App\Filament\Pages;

use App\Enums\NotificationEvent;
use App\Filament\Concerns\GuardsAdminActions;
use App\Jobs\Vpn\ApplyLockdown;
use App\Services\Lockdown\LockdownManager;
use App\Services\Notifications\Notifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use PragmaRX\Google2FA\Google2FA;

/**
 * The big deliberate switch: suspend every user on every service at once, and
 * lift it later. Both directions are confirmed with the operator's current app
 * TOTP code (the panel's own second factor), and the whole page is admin-only.
 */
class Lockdown extends Page
{
    use GuardsAdminActions;

    protected string $view = 'filament.pages.lockdown';

    protected static ?int $navigationSort = 95;

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return 'heroicon-o-lock-closed';
    }

    public static function getNavigationLabel(): string
    {
        return __('Lockdown');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Lockdown');
    }

    /**
     * This page is a mutating control with no read value -- unlike the log
     * views, there is nothing here for an auditor to see. Gate access, and hide
     * it from the sidebar, for non-admins.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function isEngaged(): bool
    {
        return app(LockdownManager::class)->isEngaged();
    }

    /**
     * @return array{engaged_at: string, engaged_by: int, engaged_by_email: string}|null
     */
    public function lockdownState(): ?array
    {
        return app(LockdownManager::class)->state();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('engage')
                ->label(__('Engage lockdown'))
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (): bool => static::canAccess() && ! $this->isEngaged())
                ->modalIcon('heroicon-o-lock-closed')
                ->modalHeading(__('Engage lockdown'))
                ->modalDescription(__('This immediately suspends every user on every service, blocking all VPN access at once. Enter your current authenticator code to confirm.'))
                ->modalSubmitActionLabel(__('Engage lockdown'))
                ->schema($this->codeField())
                ->action(fn (array $data) => $this->engage($data['code'] ?? '')),
            Action::make('lift')
                ->label(__('Lift lockdown'))
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => static::canAccess() && $this->isEngaged())
                ->modalIcon('heroicon-o-lock-open')
                ->modalHeading(__('Lift lockdown'))
                ->modalDescription(__('This restores every user that lockdown suspended and re-applies each service. Enter your current authenticator code to confirm.'))
                ->modalSubmitActionLabel(__('Lift lockdown'))
                ->schema($this->codeField())
                ->action(fn (array $data) => $this->lift($data['code'] ?? '')),
        ];
    }

    /**
     * @return array<int, TextInput>
     */
    private function codeField(): array
    {
        return [
            TextInput::make('code')
                ->label(__('Authenticator code'))
                ->helperText(__('The 6-digit code your authenticator app shows right now.'))
                ->autocomplete(false)
                ->required(),
        ];
    }

    public function engage(string $code): void
    {
        $this->abortUnlessAdmin();
        $this->assertValidCode($code);

        app(LockdownManager::class)->engage(auth()->user());
        ApplyLockdown::dispatch(true);

        // Operator alert -- plain English, like every other Notifier::event
        // call. Says who threw the switch for the audit trail.
        app(Notifier::class)->event(
            NotificationEvent::LockdownEngaged,
            'Panel lockdown engaged',
            'Every service user is being suspended by '.auth()->user()->email.'. All VPN access is blocked until it is lifted.',
        );

        Notification::make()
            ->title(__('Lockdown engaged'))
            ->body(__('Every user is being suspended on the privileged worker. All VPN access is blocked until you lift it.'))
            ->danger()
            ->persistent()
            ->send();
    }

    public function lift(string $code): void
    {
        $this->abortUnlessAdmin();
        $this->assertValidCode($code);

        app(LockdownManager::class)->lift();
        ApplyLockdown::dispatch(false);

        app(Notifier::class)->event(
            NotificationEvent::LockdownEngaged,
            'Panel lockdown lifted',
            'Lifted by '.auth()->user()->email.'. Users that lockdown suspended are being restored.',
        );

        Notification::make()
            ->title(__('Lockdown lifted'))
            ->body(__('Users that lockdown suspended are being restored on the privileged worker.'))
            ->success()
            ->send();
    }

    /**
     * Both directions are confirmed with the operator's own current TOTP code,
     * verified against their encrypted authenticator secret with google2fa
     * (verifyKey, +/-1 step). This step-up needs an authenticator-app code, so
     * an account with only a passkey (no TOTP secret) cannot confirm -- refuse
     * and say so, rather than letting an unverifiable account throw the switch.
     */
    private function assertValidCode(string $code): void
    {
        $user = auth()->user();

        if (! ($user?->hasEnabledTwoFactorAuthentication() ?? false)) {
            Notification::make()
                ->title(__('Set up an authenticator app first'))
                ->body(__('This control is confirmed with your authenticator code. Add an authenticator app from the two-factor menu, then try again.'))
                ->warning()
                ->send();

            throw new Halt;
        }

        $secret = decrypt($user->two_factor_secret);

        $clean = preg_replace('/\s+/', '', $code);

        if ($clean === '' || ! (new Google2FA)->verifyKey($secret, $clean)) {
            Notification::make()
                ->title(__('That code is not valid'))
                ->body(__('Check the current code in your authenticator app and try again.'))
                ->danger()
                ->send();

            throw new Halt;
        }
    }
}
