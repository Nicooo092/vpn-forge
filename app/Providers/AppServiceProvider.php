<?php

namespace App\Providers;

use App\Models\AdminIpRule;
use App\Models\Blocklist;
use App\Models\NotificationChannel;
use App\Models\Service;
use App\Models\ServiceUser;
use App\Models\ServiceUserTemplate;
use App\Models\User;
use App\Observers\ServiceObserver;
use App\Policies\AdminIpRulePolicy;
use App\Policies\BlocklistPolicy;
use App\Policies\NotificationChannelPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServiceUserPolicy;
use App\Policies\ServiceUserTemplatePolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Service::observe(ServiceObserver::class);

        // An admin passes every authorisation check; returning null (not false)
        // for anyone else lets the individual gate/policy decide, so an auditor
        // is only ever granted what is explicitly allowed. Read access is open
        // to any operator; mutation is gated per action.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        // Read for everyone, write for admins only (the policies use the
        // ReadOnlyForAuditors trait). Registered explicitly rather than by
        // discovery so the mapping is obvious and cannot silently break.
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(ServiceUser::class, ServiceUserPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Blocklist::class, BlocklistPolicy::class);
        Gate::policy(NotificationChannel::class, NotificationChannelPolicy::class);
        Gate::policy(AdminIpRule::class, AdminIpRulePolicy::class);
        Gate::policy(ServiceUserTemplate::class, ServiceUserTemplatePolicy::class);
    }
}
