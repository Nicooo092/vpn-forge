#!/bin/bash
#
# vpn-forge uninstaller -- the actual teardown steps.
# Sourced by installer/uninstall.sh (which sources common.sh first for its
# output/prompt helpers). Not meant to be executed directly.
#
# Every function here is best-effort and idempotent: re-running after a
# partial removal simply skips whatever is already gone. Nothing aborts the
# run on a single failure (uninstall.sh deliberately does NOT set -e), so one
# stubborn unit or a missing directory never leaves the rest behind.

# These are normally set by uninstall.sh before this file is sourced; default
# them so the file is also safe to source on its own.
DRY_RUN=${DRY_RUN:-false}
ASSUME_YES=${ASSUME_YES:-false}
APP_DIR=${APP_DIR:-/var/www/vpnforge}

# run <cmd...> -- echoes the command under --dry-run, otherwise runs it and
# warns (without aborting) on failure. Used for the plainly destructive steps.
run() {
  if [ "$DRY_RUN" = true ]; then
    output "[dry-run] $*"
  else
    "$@" || warning "Command failed (continuing): $*"
  fi
}

# confirm <prompt> [default=n] -- yesno, unless --yes was passed.
confirm() {
  local prompt=$1
  local default=${2:-n}
  [ "$ASSUME_YES" = true ] && return 0
  yesno "$prompt" "$default"
}

# ------------------------------------------------------------------
# Small readers / converters
# ------------------------------------------------------------------

# get_env_value <env_file> <KEY> -- prints the value, quotes stripped.
get_env_value() {
  local file=$1 key=$2 val
  [ -f "$file" ] || return 0
  val=$(grep -E "^${key}=" "$file" 2>/dev/null | head -1 | cut -d= -f2-)
  val=${val%\"}; val=${val#\"}; val=${val%\'}; val=${val#\'}
  printf '%s' "$val"
}

# get_yaml_value <yaml_file> <key> -- prints a top-level "key: value" value.
get_yaml_value() {
  local file=$1 key=$2 val
  [ -f "$file" ] || return 0
  val=$(grep -E "^[[:space:]]*${key}:" "$file" 2>/dev/null | head -1 | sed -E "s/^[[:space:]]*${key}:[[:space:]]*//")
  val=${val%\"}; val=${val#\"}; val=${val%\'}; val=${val#\'}
  printf '%s' "$val"
}

# mask2prefix <dotted-mask> -- e.g. 255.255.255.0 -> 24. Used to rebuild a
# service's subnet CIDR from an OpenVPN server.conf `server <net> <mask>` line.
mask2prefix() {
  local octet prefix=0
  for octet in ${1//./ }; do
    case $octet in
      255) prefix=$((prefix + 8)) ;;
      254) prefix=$((prefix + 7)) ;;
      252) prefix=$((prefix + 6)) ;;
      248) prefix=$((prefix + 5)) ;;
      240) prefix=$((prefix + 4)) ;;
      224) prefix=$((prefix + 3)) ;;
      192) prefix=$((prefix + 2)) ;;
      128) prefix=$((prefix + 1)) ;;
      0) ;;
      *) ;;
    esac
  done
  echo "$prefix"
}

# discover_ifaces -- every vpn-forge service interface name, from the config
# files the drivers write: WireGuard confs, per-service OpenVPN PKI dirs, and
# per-service dnsmasq confs. One name per line, de-duplicated.
discover_ifaces() {
  {
    if [ -d /etc/wireguard ]; then
      for f in /etc/wireguard/*.conf; do [ -e "$f" ] && basename "$f" .conf; done
    fi
    if [ -d /etc/openvpn/vpnforge ]; then
      for d in /etc/openvpn/vpnforge/*/; do [ -d "$d" ] && basename "$d"; done
    fi
    if [ -d /etc/vpnforge/dnsmasq ]; then
      for f in /etc/vpnforge/dnsmasq/*.conf; do [ -e "$f" ] && basename "$f" .conf; done
    fi
  } 2>/dev/null | sort -u
}

# ------------------------------------------------------------------
# systemd units
# ------------------------------------------------------------------

disable_unit() {
  local unit=$1
  if [ "$DRY_RUN" = true ]; then
    output "[dry-run] systemctl disable --now ${unit}"
    return 0
  fi
  if systemctl cat "$unit" >/dev/null 2>&1 || systemctl is-active --quiet "$unit" 2>/dev/null; then
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
    output "  disabled ${unit}"
  fi
}

stop_services() {
  output "Stopping and disabling per-service units (wg-quick@, openvpn-server@, vpnforge-dnsmasq@)..."
  local iface unit
  while IFS= read -r iface; do
    [ -n "$iface" ] || continue
    for unit in "wg-quick@${iface}" "openvpn-server@${iface}" "vpnforge-dnsmasq@${iface}"; do
      disable_unit "$unit"
    done
  done < <(discover_ifaces)

  output "Stopping and disabling the worker and capture agent..."
  disable_unit vpnforge-worker
  disable_unit vpnforge-agent
}

remove_systemd_units() {
  output "Removing systemd unit files..."
  run rm -f /etc/systemd/system/vpnforge-worker.service \
    /etc/systemd/system/vpnforge-agent.service \
    /etc/systemd/system/vpnforge-dnsmasq@.service
  if [ "$DRY_RUN" = false ]; then
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl reset-failed >/dev/null 2>&1 || true
  fi
}

# ------------------------------------------------------------------
# Interfaces / NAT / traffic shaping
# ------------------------------------------------------------------

# Delete every POSTROUTING MASQUERADE rule for a given source subnet,
# regardless of which egress interface it names (the egress can have been
# customised per service). Reverses -A into -D on the live rule text.
remove_nat_source() {
  local cidr=$1 rule
  command -v iptables >/dev/null 2>&1 || return 0
  [ -n "$cidr" ] || return 0
  iptables -t nat -S POSTROUTING 2>/dev/null | grep -- "-s ${cidr} " | grep -- '-j MASQUERADE' | while IFS= read -r rule; do
    rule=${rule/#-A /-D }
    if [ "$DRY_RUN" = true ]; then
      output "[dry-run] iptables -t nat ${rule}"
    else
      # shellcheck disable=SC2086
      iptables -t nat ${rule} >/dev/null 2>&1 || true
    fi
  done
}

# Delete the FORWARD ACCEPT rules that reference a tunnel interface by name.
remove_forward_iface() {
  local iface=$1 rule
  command -v iptables >/dev/null 2>&1 || return 0
  iptables -S FORWARD 2>/dev/null | grep -E -- "(-i|-o) ${iface} " | while IFS= read -r rule; do
    rule=${rule/#-A /-D }
    if [ "$DRY_RUN" = true ]; then
      output "[dry-run] iptables ${rule}"
    else
      # shellcheck disable=SC2086
      iptables ${rule} >/dev/null 2>&1 || true
    fi
  done
}

delete_link() {
  local iface=$1
  if [ "$DRY_RUN" = true ]; then
    output "[dry-run] tc/ip teardown for ${iface}"
    return 0
  fi
  tc qdisc del dev "$iface" root >/dev/null 2>&1 || true
  tc qdisc del dev "$iface" ingress >/dev/null 2>&1 || true
  ip link delete "$iface" >/dev/null 2>&1 || true
}

# Per-service upload-shaping mirror devices, named vpnfb<service-id> by
# App\Services\Vpn\TrafficShaper. Removed here in case any survived a service
# that was not cleanly torn down.
remove_ifb_devices() {
  command -v ip >/dev/null 2>&1 || return 0
  local dev
  ip -o link show type ifb 2>/dev/null | awk -F': ' '{print $2}' | awk -F'@' '{print $1}' | while IFS= read -r dev; do
    case "$dev" in
      vpnfb*)
        if [ "$DRY_RUN" = true ]; then
          output "[dry-run] ip link delete ${dev}"
        else
          ip link delete "$dev" >/dev/null 2>&1 || true
        fi
        ;;
    esac
  done
}

teardown_networking() {
  output "Tearing down interfaces, NAT rules and traffic shaping..."
  local iface cidr conf net mask prefix

  # WireGuard: the exact source subnet is in the conf's PostUp line.
  if [ -d /etc/wireguard ]; then
    for f in /etc/wireguard/*.conf; do
      [ -e "$f" ] || continue
      iface=$(basename "$f" .conf)
      cidr=$(grep -oiE 'POSTROUTING -s [0-9./]+' "$f" | head -1 | awk '{print $3}')
      remove_nat_source "$cidr"
      remove_forward_iface "$iface"
      delete_link "$iface"
    done
  fi

  # OpenVPN: rebuild the subnet CIDR from the server.conf `server` directive,
  # and drop the per-service server config from the distro-owned unit dir.
  if [ -d /etc/openvpn/vpnforge ]; then
    for d in /etc/openvpn/vpnforge/*/; do
      [ -d "$d" ] || continue
      iface=$(basename "$d")
      conf="/etc/openvpn/server/${iface}.conf"
      if [ -f "$conf" ]; then
        net=$(awk '/^server /{print $2; exit}' "$conf")
        mask=$(awk '/^server /{print $3; exit}' "$conf")
        if [ -n "$net" ] && [ -n "$mask" ]; then
          prefix=$(mask2prefix "$mask")
          remove_nat_source "${net}/${prefix}"
        fi
      fi
      remove_forward_iface "$iface"
      delete_link "$iface"
      run rm -f "/etc/openvpn/server/${iface}.conf"
    done
  fi

  remove_ifb_devices
  output "Note: net.ipv4.ip_forward and the loaded 'ifb' module stay until the next reboot."
}

# ------------------------------------------------------------------
# polkit / binary / cron / nginx
# ------------------------------------------------------------------

remove_polkit() {
  output "Removing the polkit rule..."
  run rm -f /etc/polkit-1/rules.d/49-vpnforge-worker.rules
}

remove_binary() {
  output "Removing the capture agent binary..."
  run rm -f /usr/local/bin/vpnforge-agent
}

remove_cron() {
  command -v crontab >/dev/null 2>&1 || return 0
  if ! crontab -u www-data -l 2>/dev/null | grep -q 'schedule:run'; then
    output "No vpn-forge scheduler cron line found (already removed)."
    return 0
  fi
  if [ "$DRY_RUN" = true ]; then
    output "[dry-run] remove the www-data schedule:run cron line"
    return 0
  fi
  local remaining
  remaining=$(crontab -u www-data -l 2>/dev/null | grep -v 'schedule:run' || true)
  if [ -n "$remaining" ]; then
    printf '%s\n' "$remaining" | crontab -u www-data -
  else
    crontab -u www-data -r 2>/dev/null || true
  fi
  success "Removed the www-data scheduler cron line."
}

remove_nginx() {
  output "Removing the nginx vhost..."
  run rm -f /etc/nginx/sites-enabled/vpnforge.conf /etc/nginx/sites-available/vpnforge.conf
  run rm -f /var/log/nginx/vpnforge-error.log
  if [ "$DRY_RUN" = false ] && command -v nginx >/dev/null 2>&1 && systemctl is-active --quiet nginx 2>/dev/null; then
    if nginx -t >/dev/null 2>&1; then
      systemctl reload nginx >/dev/null 2>&1 || true
    fi
  fi
}

# ------------------------------------------------------------------
# Database
# ------------------------------------------------------------------

remove_database() {
  if ! command -v mariadb >/dev/null 2>&1; then
    warning "mariadb client not found; skipping database removal."
    return 0
  fi

  local env_file="${APP_DIR}/.env" db_name db_user agent_user
  db_name=$(get_env_value "$env_file" DB_DATABASE); db_name=${db_name:-vpnforge}
  db_user=$(get_env_value "$env_file" DB_USERNAME); db_user=${db_user:-vpnforge}
  agent_user=$(get_yaml_value /etc/vpnforge/agent.yml user); agent_user=${agent_user:-vpnforge_agent}

  output "Database to drop: '${db_name}'   DB users: '${db_user}', '${agent_user}' (@127.0.0.1)"
  if ! confirm "Drop the vpn-forge database and its DB users? This is irreversible." n; then
    warning "Skipped database removal."
    return 0
  fi

  if [ "$DRY_RUN" = true ]; then
    output "[dry-run] DROP DATABASE ${db_name}; DROP USER ${db_user}, ${agent_user}"
    return 0
  fi

  mariadb -e "DROP DATABASE IF EXISTS \`${db_name}\`;" 2>/dev/null || warning "Could not drop database ${db_name}."
  mariadb -e "DROP USER IF EXISTS '${db_user}'@'127.0.0.1';" 2>/dev/null || true
  mariadb -e "DROP USER IF EXISTS '${agent_user}'@'127.0.0.1';" 2>/dev/null || true
  mariadb -e "FLUSH PRIVILEGES;" 2>/dev/null || true
  success "Dropped the database and its DB users."
}

# ------------------------------------------------------------------
# System users / group
# ------------------------------------------------------------------

del_user() {
  local u=$1
  id -u "$u" >/dev/null 2>&1 || return 0
  run userdel "$u"
}

remove_system_users() {
  output "Removing system users and the shared group..."
  del_user vpnforge-worker
  del_user vpnforge-agent
  if getent group vpnforge-shared >/dev/null 2>&1; then
    run groupdel vpnforge-shared
  fi
}

# ------------------------------------------------------------------
# sysctl / modules-load / directories
# ------------------------------------------------------------------

remove_sysctl_and_modules() {
  output "Removing the sysctl drop-in and the ifb modules-load file..."
  run rm -f /etc/sysctl.d/99-vpnforge.conf /etc/modules-load.d/vpnforge-ifb.conf
}

remove_directories() {
  local remove_backups=$1
  # Leave the directory that holds this very script before deleting it.
  cd / 2>/dev/null || true

  output "Removing configuration, key and log directories..."
  run rm -f /etc/wireguard/*.conf /etc/wireguard/*.conf.new
  # Only reclaim /etc/wireguard if empty -- it is a conventional path other
  # WireGuard tooling may also use.
  if [ "$DRY_RUN" = false ] && [ -d /etc/wireguard ]; then
    rmdir /etc/wireguard 2>/dev/null || true
  fi
  run rm -rf /etc/openvpn/vpnforge
  run rm -rf /etc/vpnforge
  run rm -rf /var/log/vpnforge

  if [ "$remove_backups" = true ]; then
    run rm -rf /var/backups/vpnforge
    success "Removed /var/backups/vpnforge."
  else
    warning "Kept /var/backups/vpnforge (it holds your backups -- every key on the box)."
  fi

  run rm -rf "$APP_DIR"
  success "Removed the application directory ${APP_DIR}."
}
