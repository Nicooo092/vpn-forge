[Unit]
Description=vpn-forge traffic capture agent
After=network.target mariadb.service

[Service]
Type=simple
ExecStart=/usr/local/bin/vpnforge-agent --config=/etc/vpnforge/agent.yml
Restart=always
RestartSec=5

# No root: capture needs CAP_NET_RAW/CAP_NET_ADMIN for the packet capture
# only (granted once via `setcap` at install time, not here), and reads
# dnsmasq's log files, which are world-readable by design (they contain no
# secrets, only already-visible DNS query metadata).
User=vpnforge-agent
Group=vpnforge-agent
# Deliberately NOT NoNewPrivileges=yes: that flag blocks capabilities
# granted via `setcap` from taking effect at all (it prevents privilege
# gain through execve, which is exactly the mechanism file capabilities
# use) -- it's only safe to set on the worker unit, which uses ambient
# capabilities instead, a different mechanism NoNewPrivileges doesn't block.

[Install]
WantedBy=multi-user.target
