#!/bin/bash

setup_capture_agent() {
  output "Building the capture agent..."
  # -buildvcs=false: agent/ sits inside the vpn-forge git checkout, and Go
  # tries to embed VCS status into the binary by shelling out to git --
  # which fails here (root cloned the repo, but git's ownership checks can
  # still trip in some environments). The build doesn't need VCS stamping.
  (cd "${APP_DIR}/agent" && go build -buildvcs=false -o /usr/local/bin/vpnforge-agent .)

  output "Granting the agent capture capabilities (setcap, not root)..."
  setcap cap_net_raw,cap_net_admin=eip /usr/local/bin/vpnforge-agent

  output "Creating the vpnforge-agent system user..."
  if ! id -u vpnforge-agent >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin vpnforge-agent
  fi

  mkdir -p /etc/vpnforge /var/log/vpnforge
  chgrp vpnforge-agent /var/log/vpnforge
  chmod 755 /var/log/vpnforge

  output "Writing the agent's config file..."
  cat >/etc/vpnforge/agent.yml <<EOF
database:
  host: 127.0.0.1
  port: 3306
  user: ${AGENT_DB_USER}
  password: ${AGENT_DB_PASSWORD}
  database: ${DB_NAME}
poll_interval_seconds: 30
dns_log_dir: /var/log/vpnforge
EOF
  chown root:vpnforge-agent /etc/vpnforge/agent.yml
  chmod 640 /etc/vpnforge/agent.yml

  output "Installing the dnsmasq template unit (one instance per service)..."
  cp "${INSTALLER_DIR}/templates/systemd/vpnforge-dnsmasq@.service.tpl" \
    /etc/systemd/system/vpnforge-dnsmasq@.service

  output "Installing the capture agent systemd service..."
  cp "${INSTALLER_DIR}/templates/systemd/vpnforge-agent.service.tpl" \
    /etc/systemd/system/vpnforge-agent.service

  systemctl daemon-reload
  service_enable_now vpnforge-agent
}
