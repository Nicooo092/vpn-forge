[Unit]
Description=vpn-forge privileged queue worker
After=network.target mariadb.service

[Service]
User=vpnforge-worker
Group=vpnforge-worker
WorkingDirectory=__APP_DIR__
# Re-applies whatever doesn't survive a reboot on its own (OpenVPN NAT
# rules -- see RestoreAfterBoot's docblock) before this starts picking up
# jobs. A failure here is logged but never fails the unit itself (the
# command always exits 0), so one broken service can't block the worker.
ExecStartPre=/usr/bin/php __APP_DIR__/artisan vpn:restore-after-boot
ExecStart=/usr/bin/php __APP_DIR__/artisan queue:work database --queue=system --tries=3 --backoff=5 --sleep=3
Restart=always
RestartSec=5

# Not root: holds exactly the one Linux capability it needs (to manage
# WireGuard interfaces, OpenVPN's server process, and iptables NAT rules)
# via ambient capabilities, the standard mechanism for granting a specific
# capability to a non-root systemd service without full root or sudo.
AmbientCapabilities=CAP_NET_ADMIN
CapabilityBoundingSet=CAP_NET_ADMIN
NoNewPrivileges=yes

[Install]
WantedBy=multi-user.target
