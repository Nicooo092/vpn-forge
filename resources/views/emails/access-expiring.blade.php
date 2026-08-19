<x-mail::message>
# {{ __('Your VPN access is expiring soon') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('Your access to the ":service" VPN is scheduled to end on :date.', ['service' => $user->service->name, 'date' => $expiresAt]) }}

{{ __('If you need to keep it, please contact whoever set up your VPN for you.') }}
</x-mail::message>
