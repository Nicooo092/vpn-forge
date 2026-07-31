<?php

namespace App\Services\Vpn;

use App\Models\Service;
use App\Models\ServiceUser;

/**
 * The one abstraction every protocol-specific driver implements. Filament
 * resources and Jobs must only ever call through this interface and never
 * branch on Service::protocol themselves -- that's what lets a new VPN
 * protocol be added later as a new driver class, without touching the UI
 * or job layer at all.
 *
 * Every method here runs inside the privileged queue worker (never the web
 * process) except buildClientConfig(), which only reads already-generated
 * key/certificate material and needs no elevated privilege.
 */
interface VpnProtocolDriver
{
    /**
     * First-time setup for a newly created service: generate server keys/CA,
     * write the initial config, bring the interface/server up, apply NAT
     * rules. Called once, right after the Service row is created.
     */
    public function provisionService(Service $service): void;

    /**
     * Regenerate the service's config from the current database state and
     * hot-apply it (e.g. `wg syncconf` for WireGuard). Called whenever the
     * service's own settings change, or after a user is added/removed.
     */
    public function applyServiceConfig(Service $service): void;

    /**
     * Generate whatever key/certificate material this protocol needs for a
     * new user and persist it onto the $user model (public key, common
     * name, certificate serial, tunnel IP, ...). Does not itself need to
     * reload the running server for every protocol -- OpenVPN needs no
     * reload at all; WireGuard's caller should follow up with
     * applyServiceConfig().
     */
    public function addUser(Service $service, ServiceUser $user): void;

    /**
     * Revoke/remove a user: for OpenVPN this revokes the certificate and
     * regenerates the CRL; for WireGuard this just means the peer is no
     * longer in the next applyServiceConfig() render. Marks the user
     * revoked but does not delete the row (kept for the connection/traffic
     * log history's foreign key).
     */
    public function removeUser(Service $service, ServiceUser $user): void;

    /**
     * Build the single downloadable config file (.conf / .ovpn) for an
     * existing user. Safe to call from the unprivileged web process --
     * only reads already-generated material, does not touch the network
     * stack.
     */
    public function buildClientConfig(Service $service, ServiceUser $user): ClientConfigFile;

    /**
     * @return array<string, PeerStatus> keyed by tunnel IP
     */
    public function pollStatus(Service $service): array;

    /**
     * Full teardown: bring the interface/server down, remove NAT rules,
     * disable the systemd unit, delete config/PKI files. Called when a
     * Service record is deleted, so removing one from the panel doesn't
     * leave orphaned interfaces or iptables rules behind.
     */
    public function removeService(Service $service): void;
}
