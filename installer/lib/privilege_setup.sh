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

  # Compiled subscription blocklists: written by the worker, read by every
  # resolver. dnsmasq reads its addn-hosts file as root at start, so 755 with
  # world-read is enough -- unlike dnsmasq/ this holds no config the worker
  # needs to keep private.
  mkdir -p /etc/vpnforge/blocklists
  chown vpnforge-worker:vpnforge-worker /etc/vpnforge/blocklists
  chmod 755 /etc/vpnforge/blocklists

  # /var/log/vpnforge: DnsmasqManager (running as vpnforge-worker) creates
  # each service's DNS log file here before dnsmasq itself ever starts, so
  # the worker needs *write* on this directory -- that means group write,
  # i.e. 775 and not 755. vpnforge-agent only has to traverse the directory
  # to open those files for tailing; each individual file is separately
  # chgrp'd to vpnforge-agent and chmod 640 by the dnsmasq unit's
  # ExecStartPost, so content access stays gated per-file and "other" needs
  # nothing beyond r-x here. setgid keeps the group on anything created,
  # whoever creates it.
  #
  # setup_capture_agent() runs after this one and must NOT chgrp this
  # directory to vpnforge-agent: that silently takes the worker's write bit
  # away, provisioning then dies on "Failed to open stream: Permission
  # denied", the service is left status=error, and because
  # PollAllServiceStatuses only polls Active services the panel shows no
  # telemetry at all.
  chgrp vpnforge-worker /var/log/vpnforge
  chmod 2775 /var/log/vpnforge

  # www-data and vpnforge-worker cannot see each other's files, which is the
  # point -- but two directories genuinely belong to both of them, so one
  # shared group carries exactly those and nothing else.
  output "Creating the shared group for the panel and the worker..."
  groupadd -f vpnforge-shared
  usermod -aG vpnforge-shared vpnforge-worker
  usermod -aG vpnforge-shared www-data

  # Backups: written by the worker (the only account that can read the
  # OpenVPN CA), downloaded through the panel. 2770 because the contents are
  # every private key on the machine, setgid so archives inherit the group
  # whichever account writes them.
  mkdir -p /var/backups/vpnforge
  chown root:vpnforge-shared /var/backups/vpnforge
  chmod 2770 /var/backups/vpnforge

  # Laravel's log: both processes write it. Without this the worker cannot
  # open it, and because Monolog throws when it cannot log, a single failed
  # job takes the whole worker down with it rather than being recorded.
  mkdir -p "${APP_DIR}/storage/logs"
  chgrp -R vpnforge-shared "${APP_DIR}/storage/logs"
  chmod 2775 "${APP_DIR}/storage/logs"
  find "${APP_DIR}/storage/logs" -type f -exec chmod 664 {} +

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
