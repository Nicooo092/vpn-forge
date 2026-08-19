#!/bin/bash
#
# vpn-forge uninstaller.
#
# Usage (run as root on the server where vpn-forge is installed):
#   sudo bash /var/www/vpnforge/installer/uninstall.sh [--dry-run] [--yes]
#
#   --dry-run   Print exactly what would be removed, change nothing.
#   -y, --yes   Assume "yes" to every confirmation (non-interactive).
#
# Cleanly reverses what installer/install.sh set up: it stops and disables the
# systemd units (vpnforge-worker, vpnforge-agent, and every per-service
# wg-quick@, openvpn-server@ and vpnforge-dnsmasq@ instance), tears down each
# service's interface, NAT and tc shaping, removes the units, the polkit rule,
# the agent binary, the nginx vhost, the scheduler cron line, the system
# users/group, the sysctl/modules drop-ins, drops the database and its DB
# users, and removes the app, config, key and log directories. The backups
# directory is kept unless you explicitly choose to delete it.
#
# Installed OS packages (nginx, MariaDB, PHP, WireGuard, OpenVPN, dnsmasq, Go,
# ...) are deliberately left in place -- they are shared with the rest of the
# system and removing them is a separate, manual decision.
#
# Deliberately NOT `set -e`: teardown is best-effort and idempotent, and a
# single missing unit or already-deleted path must never abort the rest.

set -uo pipefail

INSTALLER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-/var/www/vpnforge}"
DRY_RUN=false
ASSUME_YES=false

usage() {
  sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=true ;;
    -y | --yes) ASSUME_YES=true ;;
    -h | --help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      echo "Try: $(basename "$0") --help" >&2
      exit 1
      ;;
  esac
done

# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/common.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/uninstall_steps.sh"

main() {
  check_root

  print_brake 60
  warning "vpn-forge uninstaller"
  print_brake 60
  output "This removes the vpn-forge worker, capture agent, every VPN service's"
  output "interface, NAT and shaping rules, the systemd units, the nginx vhost, the"
  output "polkit rule, system users/group, the scheduler cron line, and these dirs:"
  output "  ${APP_DIR}"
  output "  /etc/wireguard/*  /etc/openvpn/vpnforge  /etc/vpnforge  /var/log/vpnforge"
  output "You will be asked separately about the database and about /var/backups/vpnforge."
  output "OS packages (nginx, MariaDB, PHP, WireGuard, OpenVPN, ...) are left installed."
  [ "$DRY_RUN" = true ] && warning "DRY RUN: nothing will actually be changed."
  print_brake 60

  if ! confirm "Proceed with uninstalling vpn-forge?" n; then
    output "Aborted. Nothing was changed."
    exit 0
  fi

  local remove_backups=false
  if [ -d /var/backups/vpnforge ]; then
    if confirm "Also delete /var/backups/vpnforge? It contains every key on the box." n; then
      remove_backups=true
    fi
  fi

  stop_services
  teardown_networking
  remove_systemd_units
  remove_polkit
  remove_binary
  remove_cron
  remove_nginx
  remove_database
  remove_system_users
  remove_sysctl_and_modules
  remove_directories "$remove_backups"

  print_brake 60
  success "vpn-forge has been uninstalled."
  [ "$DRY_RUN" = true ] && warning "That was a dry run -- re-run without --dry-run to apply."
  output "OS packages were left installed; remove them by hand if you no longer need them."
  print_brake 60
}

main
