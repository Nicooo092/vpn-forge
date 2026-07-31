#!/bin/bash
#
# vpn-forge installer -- shared helpers.
# Sourced by install.sh and every other installer/lib/*.sh file.
# Not meant to be executed directly.

# ------------------------------------------------------------------
# Output helpers
# ------------------------------------------------------------------

COLOR_RED='\033[0;31m'
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_NC='\033[0m'

output() {
  echo -e "* $1"
}

success() {
  echo -e "${COLOR_GREEN}* $1${COLOR_NC}"
}

warning() {
  echo -e "${COLOR_YELLOW}* $1${COLOR_NC}"
}

error() {
  echo -e "${COLOR_RED}* $1${COLOR_NC}" 1>&2
}

print_brake() {
  local n=$1
  for ((i = 0; i < n; i++)); do
    echo -n "#"
  done
  echo ""
}

# ------------------------------------------------------------------
# Root / OS checks -- v1 targets Ubuntu 24.04 LTS only. This project has
# meaningfully more moving parts than a simple installer script (two VPN
# protocol drivers, a privileged queue worker, a separate Go capture agent,
# per-service dnsmasq instances), and several OS-version-specific details
# (systemd unit naming, PHP-FPM socket paths, package availability) differ
# across Ubuntu/Debian versions -- narrowing to one well-tested target
# keeps this reliable rather than spread thin. Broader OS support can come
# later once this is proven.
# ------------------------------------------------------------------

check_root() {
  if [ "$(id -u)" -ne 0 ]; then
    error "This script must be run as root (or with sudo)."
    exit 1
  fi
}

require_os_supported() {
  if [ ! -f /etc/os-release ]; then
    error "Cannot detect OS: /etc/os-release not found."
    exit 1
  fi

  # shellcheck disable=SC1091
  . /etc/os-release

  if [ "$ID" != "ubuntu" ] || [ "$VERSION_ID" != "24.04" ]; then
    error "Unsupported OS: ${PRETTY_NAME:-unknown}."
    error "vpn-forge only supports Ubuntu 24.04 LTS for now."
    exit 1
  fi
}

# ------------------------------------------------------------------
# Package management
# ------------------------------------------------------------------

apt_update() {
  DEBIAN_FRONTEND=noninteractive apt-get update -y -qq
}

apt_install() {
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@"
}

# ------------------------------------------------------------------
# Prompt helpers
# ------------------------------------------------------------------

# required_input <var_name> <prompt> [default]
required_input() {
  local __resultvar=$1
  local prompt=$2
  local default=${3:-}
  local input

  while true; do
    if [ -n "$default" ]; then
      read -rp "$prompt [$default]: " input
      input=${input:-$default}
    else
      read -rp "$prompt: " input
    fi

    if [ -n "$input" ]; then
      printf -v "$__resultvar" '%s' "$input"
      return 0
    fi
    warning "This field is required."
  done
}

# email_input <var_name> <prompt>
email_input() {
  local __resultvar=$1
  local prompt=$2
  local input

  while true; do
    read -rp "$prompt: " input
    if [[ "$input" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
      printf -v "$__resultvar" '%s' "$input"
      return 0
    fi
    warning "Please enter a valid email address."
  done
}

# password_input <var_name> <prompt> [min_length=8]
password_input() {
  local __resultvar=$1
  local prompt=$2
  local minlen=${3:-8}
  local pass pass2

  while true; do
    read -rsp "$prompt: " pass
    echo ""
    if [ ${#pass} -lt "$minlen" ]; then
      warning "Password must be at least $minlen characters."
      continue
    fi
    read -rsp "Confirm password: " pass2
    echo ""
    if [ "$pass" != "$pass2" ]; then
      warning "Passwords did not match, try again."
      continue
    fi
    printf -v "$__resultvar" '%s' "$pass"
    return 0
  done
}

# yesno <prompt> [default=y]
yesno() {
  local prompt=$1
  local default=${2:-y}
  local suffix="[Y/n]"
  local input

  [ "$default" = "n" ] && suffix="[y/N]"
  read -rp "$prompt $suffix: " input
  input=${input:-$default}
  case "$input" in
    [Yy]*) return 0 ;;
    *) return 1 ;;
  esac
}

# gen_password [length=24]
gen_password() {
  local len=${1:-24}
  # `head -c` closing the pipe as soon as it has enough bytes sends SIGPIPE
  # to `tr` (it's still trying to write more from the unbounded /dev/urandom
  # stream) -- expected and harmless, but with `pipefail` active it would
  # otherwise be reported as a failure and abort the script.
  tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$len" || true
}

# ------------------------------------------------------------------
# systemd helper
# ------------------------------------------------------------------

# service_enable_now <service_name>
service_enable_now() {
  local svc=$1
  systemctl enable --now "$svc" >/dev/null
  sleep 2
  if ! systemctl is-active --quiet "$svc"; then
    error "$svc did not start correctly. Recent logs:"
    journalctl -u "$svc" --no-pager -n 30
    exit 1
  fi
}

# ------------------------------------------------------------------
# Laravel .env helper
# ------------------------------------------------------------------

# ensure_env_key <env_file> <KEY> <value>
# Idempotent upsert: replaces the line if KEY already exists, appends otherwise.
ensure_env_key() {
  local file=$1
  local key=$2
  local value=$3

  if grep -qE "^${key}=" "$file" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
  else
    echo "${key}=${value}" >>"$file"
  fi
}
