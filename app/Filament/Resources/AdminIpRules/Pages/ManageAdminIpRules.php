<?php

namespace App\Filament\Resources\AdminIpRules\Pages;

use App\Filament\Resources\AdminIpRules\AdminIpRuleResource;
use App\Models\AdminIpRule;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageAdminIpRules extends ManageRecords
{
    protected static string $resource = AdminIpRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'Once any rule exists, only listed networks — and loopback — can reach the panel. '
            .'Your current address is '.request()->ip().'. '
            .'Locked out (e.g. your home IP changed)? Run  php artisan vpnforge:ip-allowlist-clear  over SSH to reset.';
    }

    protected function getHeaderActions(): array
    {
        return [
            // The most common (and safest) first rule: the address you're on now.
            Action::make('addCurrent')
                ->label('Allow my current IP')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->action(function (): void {
                    $ip = request()->ip();

                    AdminIpRule::firstOrCreate(['cidr' => $ip], ['label' => 'Added from the panel']);

                    Notification::make()
                        ->title("Allowed {$ip}")
                        ->body('Add any other networks you need before relying on the restriction.')
                        ->success()
                        ->send();
                }),

            CreateAction::make()->label('Add network'),
        ];
    }
}
