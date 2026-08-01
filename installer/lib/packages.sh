#!/bin/bash
#
# System dependency installation. Ubuntu 24.04 ships PHP 8.3 natively, so
# unlike some other installers there's no need for a third-party PHP PPA
# here -- one less moving part, kept that way deliberately by scoping to a
# single OS version (see require_os_supported() in lib/common.sh).

install_packages() {
  output "Updating package lists..."
  apt_update

  output "Installing web/database stack (nginx, MariaDB, PHP 8.3, Composer prerequisites)..."
  apt_install curl wget git unzip tar nginx mariadb-server \
    php8.3-cli php8.3-fpm php8.3-gd php8.3-mysql php8.3-mbstring php8.3-bcmath \
    php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-sqlite3

  output "Installing WireGuard, OpenVPN, easy-rsa, dnsmasq..."
  apt_install wireguard wireguard-tools openvpn easy-rsa dnsmasq iptables

  output "Installing Go and libpcap (needed to build the capture agent)..."
  apt_install golang-go gcc libpcap-dev

  output "Installing Composer..."
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null

  systemctl enable --now mariadb nginx php8.3-fpm >/dev/null

  # dnsmasq's *default* systemd instance would try to grab port 53 on
  # every interface, colliding with Ubuntu's systemd-resolved (127.0.0.53)
  # and with the per-service instances this installer manages instead
  # (see capture_agent_setup.sh) -- keep only the per-service template
  # unit, never the stock one.
  systemctl disable --now dnsmasq >/dev/null 2>&1 || true

  output "Enabling IP forwarding..."
  echo 'net.ipv4.ip_forward=1' >/etc/sysctl.d/99-vpnforge.conf
  sysctl -p /etc/sysctl.d/99-vpnforge.conf >/dev/null

  # Per-user upload shaping mirrors a tunnel's ingress onto an IFB device
  # (see App\Services\Vpn\TrafficShaper). The privileged worker can create
  # those devices with CAP_NET_ADMIN but cannot load a kernel module, so the
  # ifb module is loaded here and at every boot.
  output "Loading the ifb kernel module for traffic shaping..."
  echo 'ifb' >/etc/modules-load.d/vpnforge-ifb.conf
  modprobe ifb 2>/dev/null || true
}
