<x-mail::message>
# {{ __('A new sign-in to your VPN account') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('Your ":service" VPN account just connected from a network we have not seen before (:network).', ['service' => $user->service->name, 'network' => $network]) }}

{{ __('If this was you, no action is needed. If it was not, contact whoever set up your VPN for you.') }}
</x-mail::message>
