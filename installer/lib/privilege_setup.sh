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
  mkdir -p /etc/wireguard /etc/openvpn/vpnforge /etc/vpnforge/dnsmasq /var/log/vpnforge

  # /etc/wireguard and /etc/openvpn/vpnforge hold real secrets (WireGuard
  # private keys, the OpenVPN CA) -- restricted to root + vpnforge-worker
  # only.
  chgrp -R vpnforge-worker /etc/wireguard /etc/openvpn/vpnforge
  chmod -R 750 /etc/wireguard /etc/openvpn/vpnforge

  # /etc/vpnforge itself must stay traversable by everyone (755, not 750):
  # vpnforge-agent needs to reach /etc/vpnforge/agent.yml, and isn't (and
  # shouldn't be) a member of the vpnforge-worker group -- a directory's
  # own permissions gate traversal into it regardless of a file's own
  # mode. Its dnsmasq/ subdirectory (config only, no secrets) stays
  # group-restricted to vpnforge-worker, which is the only thing that
  # writes there.
  chmod 755 /etc/vpnforge
  chgrp vpnforge-worker /etc/vpnforge/dnsmasq
  chmod 750 /etc/vpnforge/dnsmasq

  output "Installing the vpnforge-worker systemd service..."
  sed "s|__APP_DIR__|${APP_DIR}|g" "${INSTALLER_DIR}/templates/systemd/vpnforge-worker.service.tpl" \
    >/etc/systemd/system/vpnforge-worker.service
  systemctl daemon-reload
  service_enable_now vpnforge-worker
}
