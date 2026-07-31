#!/bin/bash
#
# The real vpn-forge installer. Expects to be run from within a cloned copy
# of the repo (install.sh at the repo root handles that and hands off
# here) -- APP_DIR and VPNFORGE_REPO_URL are expected to already be set in
# the environment by that point.

set -euo pipefail

INSTALLER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-/var/www/vpnforge}"
VPNFORGE_REPO_URL="${VPNFORGE_REPO_URL:-https://github.com/Nicooo092/vpn-forge.git}"

# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/common.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/packages.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/database_setup.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/app_install.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/webserver_setup.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/privilege_setup.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/capture_agent_setup.sh"
# shellcheck disable=SC1091
. "${INSTALLER_DIR}/lib/firewall_hint.sh"

gather_input() {
  print_brake 60
  output "vpn-forge installation"
  print_brake 60

  required_input APP_HOST "Domain name for the panel (e.g. vpn.example.com) or this server's public IP"

  SCHEME="http"
  if [[ ! "$APP_HOST" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    if yesno "Configure free SSL via Let's Encrypt for ${APP_HOST}?" y; then
      SCHEME="https"
    fi
  else
    warning "SSL requires a real domain pointing at this server; skipping since you entered an IP."
  fi

  required_input DB_NAME "Database name" "vpnforge"
  required_input DB_USER "Database username" "vpnforge"
  DB_PASSWORD=$(gen_password 32)
  output "Generated database password: ${DB_PASSWORD}"

  # Consumed by database_setup.sh and capture_agent_setup.sh (sourced
  # above); a cross-function usage shellcheck's analysis can't follow.
  # shellcheck disable=SC2034
  AGENT_DB_USER="vpnforge_agent"
  AGENT_DB_PASSWORD=$(gen_password 32)
  output "Generated capture agent database password: ${AGENT_DB_PASSWORD}"

  email_input ADMIN_EMAIL "Admin account email"
  required_input ADMIN_USERNAME "Admin account username" "admin"
  if yesno "Auto-generate the admin password?" y; then
    ADMIN_PASSWORD=$(gen_password 16)
    output "Generated admin password: ${ADMIN_PASSWORD}"
  else
    password_input ADMIN_PASSWORD "Admin account password"
  fi

  local detected_tz
  detected_tz=$(cat /etc/timezone 2>/dev/null || echo "UTC")
  required_input TIMEZONE "Timezone" "$detected_tz"

  print_brake 60
  output "Summary:"
  output "  Panel URL:  ${SCHEME}://${APP_HOST}"
  output "  Database:   ${DB_NAME} / ${DB_USER}"
  output "  Admin:      ${ADMIN_USERNAME} <${ADMIN_EMAIL}>"
  output "  Timezone:   ${TIMEZONE}"
  print_brake 60

  yesno "Proceed with installation?" y || {
    output "Aborted."
    exit 0
  }
}

print_summary() {
  print_brake 60
  success "vpn-forge installed successfully!"
  print_brake 60
  output "Panel URL:        ${SCHEME}://${APP_HOST}"
  output "Admin username:   ${ADMIN_USERNAME}"
  output "Admin email:      ${ADMIN_EMAIL}"
  output "Admin password:   ${ADMIN_PASSWORD}"
  output "DB password:      ${DB_PASSWORD}  (save this, it is not shown again)"
  print_brake 60
}

main() {
  check_root
  require_os_supported
  gather_input

  install_packages
  setup_database
  install_app
  configure_nginx
  configure_ssl
  setup_privileged_worker
  setup_capture_agent

  print_summary
  print_firewall_hint
}

main
