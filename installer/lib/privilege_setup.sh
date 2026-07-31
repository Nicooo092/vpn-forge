#!/bin/bash
#
# Everything needed for the "privileged worker, not root, not sudo" design:
# a dedicated system user holding exactly CAP_NET_ADMIN (via the systemd
# unit's AmbientCapabilities=, not a file capability or sudoers rule), and
# group ownership of the directories it needs to write to -- plain Unix
# permissions, no capability needed for that part.

setup_privileged_worker() {
  output "Creating the vpnforge-worker system user..."
  if ! id -u vpnforge-worker >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin vpnforge-worker
  fi

  output "Preparing directories for WireGuard, OpenVPN and dnsmasq..."
  mkdir -p /etc/wireguard /etc/openvpn/vpnforge /etc/openvpn/server /etc/vpnforge/dnsmasq /var/log/vpnforge

  # /etc/wireguard and /etc/openvpn/vpnforge hold real secrets (WireGuard
  # private keys, the OpenVPN CA) -- restricted to root + vpnforge-worker
  # only. 770, not 750: the worker doesn't just read these, it creates
  # subdirectories and writes config/key files into them directly, which
  # needs the group execute bit AND the group write bit -- group-read-only
  # would let it list/traverse but never create anything, since vpnforge-
  # worker is the sole member of this group there's no broader-exposure
  # trade-off to a wider write grant here.
  chgrp -R vpnforge-worker /etc/wireguard /etc/openvpn/vpnforge
  chmod -R 770 /etc/wireguard /etc/openvpn/vpnforge
  # Setgid so every file/subdirectory *created inside* these later (e.g.
  # each time a service's config is regenerated) inherits the vpnforge-
  # worker group automatically, regardless of the creating process's own
  # primary group -- without this, a new file's group comes from whoever
  # created it, not from the directory it landed in.
  chmod g+s /etc/wireguard /etc/openvpn/vpnforge

  # /etc/openvpn/server is where the *packaged* openvpn-server@.service
  # unit hardcodes its WorkingDirectory + a relative --config %i.conf --
  # OpenVpnDriver has to write the real server.conf here (everything it
  # references -- CA/cert/key/CRL -- stays under /etc/openvpn/vpnforge).
  # No secrets live directly in this directory, just per-service config
  # files pointing elsewhere, but it's still worker-only: only the
  # privileged worker should ever be able to write an openvpn server config.
  chgrp vpnforge-worker /etc/openvpn/server
  chmod 770 /etc/openvpn/server
  chmod g+s /etc/openvpn/server

  # /etc/vpnforge itself must stay traversable by everyone (755, not 770):
  # vpnforge-agent needs to reach /etc/vpnforge/agent.yml, and isn't (and
  # shouldn't be) a member of the vpnforge-worker group -- a directory's
  # own permissions gate traversal into it regardless of a file's own
  # mode. Its dnsmasq/ subdirectory (config only, no secrets) stays
  # group-restricted to vpnforge-worker, which is the only thing that
  # writes there -- same 770 reasoning as above, it creates files there,
  # not just reads them.
  chmod 755 /etc/vpnforge
  chgrp vpnforge-worker /etc/vpnforge/dnsmasq
  chmod 770 /etc/vpnforge/dnsmasq
  chmod g+s /etc/vpnforge/dnsmasq

  # /var/log/vpnforge: DnsmasqManager creates/truncates each service's DNS
  # log file here before dnsmasq itself ever starts. dnsmasq (which
  # subsequently owns the file day-to-day -- see vpnforge-dnsmasq@.service's
  # ExecStartPost) runs as root regardless, so this grant is purely for
  # that initial worker-side creation step.
  chgrp vpnforge-worker /var/log/vpnforge
  chmod 770 /var/log/vpnforge

  output "Granting vpnforge-worker permission to manage its systemd units..."
  # CAP_NET_ADMIN (below) covers wg/easyrsa/iptables, but systemctl
  # enable/disable/restart on wg-quick@, openvpn-server@ and
  # vpnforge-dnsmasq@ goes over D-Bus to systemd, which polkit -- not
  # capabilities -- gates. Without this, polkit's default policy demands
  # interactive authentication, which this non-interactive service can
  # never supply, and every provisioning job fails with "Interactive
  # authentication required."
  mkdir -p /etc/polkit-1/rules.d
  cp "${INSTALLER_DIR}/templates/polkit/vpnforge-worker.rules" /etc/polkit-1/rules.d/49-vpnforge-worker.rules

  output "Installing the vpnforge-worker systemd service..."
  sed "s|__APP_DIR__|${APP_DIR}|g" "${INSTALLER_DIR}/templates/systemd/vpnforge-worker.service.tpl" \
    >/etc/systemd/system/vpnforge-worker.service
  systemctl daemon-reload
  service_enable_now vpnforge-worker
}
