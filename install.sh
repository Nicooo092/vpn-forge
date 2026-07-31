#!/bin/bash
#
# vpn-forge bootstrap installer.
#
# One-line usage (run as root on a fresh Ubuntu 24.04 server):
#   curl -fsSL https://raw.githubusercontent.com/Nicooo092/vpn-forge/main/install.sh -o /tmp/vpnforge-install.sh && sudo bash /tmp/vpnforge-install.sh
#
# Deliberately not `sudo bash <(curl ...)`: this script needs real
# interactive stdin later for its prompts, which rules out `curl | sudo
# bash` (bash's stdin would be the exhausted pipe, not the terminal) --
# process substitution avoids that, but `sudo` re-executing into a new
# process doesn't reliably inherit the /dev/fd/N handle process
# substitution creates (confirmed broken on Ubuntu 24.04 during testing).
# Downloading to a real temp file first sidesteps both problems.
#
# This tiny script's only job is to get the full repository onto the
# server (it needs cloning anyway, since the Laravel app and capture agent
# source both live in it) and then hand off to the real installer that
# ships inside it -- installer/install.sh -- rather than curl-fetching
# dozens of individual files the way a pure-script installer would.

set -euo pipefail

VPNFORGE_REPO_URL="${VPNFORGE_REPO_URL:-https://github.com/Nicooo092/vpn-forge.git}"
APP_DIR="${APP_DIR:-/var/www/vpnforge}"

if [ "$(id -u)" -ne 0 ]; then
  echo "This script must be run as root (or with sudo)." >&2
  exit 1
fi

if ! command -v git >/dev/null 2>&1; then
  DEBIAN_FRONTEND=noninteractive apt-get update -y -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git
fi

if [ -d "${APP_DIR}/.git" ]; then
  git -C "$APP_DIR" pull --ff-only
else
  mkdir -p "$(dirname "$APP_DIR")"
  git clone --depth 1 "$VPNFORGE_REPO_URL" "$APP_DIR"
fi

export VPNFORGE_REPO_URL APP_DIR
exec bash "${APP_DIR}/installer/install.sh"
