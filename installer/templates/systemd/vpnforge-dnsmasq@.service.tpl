[Unit]
Description=vpn-forge dnsmasq instance for %i
After=network.target

[Service]
Type=simple
ExecStart=/usr/sbin/dnsmasq --keep-in-foreground --conf-file=/etc/vpnforge/dnsmasq/%i.conf
# dnsmasq opens its --log-facility file before it drops root privileges, so
# the file always ends up owned nobody:root regardless of any user=/group=
# directive in the per-service conf -- vpnforge-agent (not in the root
# group, by design) could never read it otherwise. Poll briefly since the
# file may not exist yet the instant this fires.
ExecStartPost=/bin/bash -c 'for i in $(seq 1 20); do [ -f /var/log/vpnforge/dns-%i.log ] && break; sleep 0.2; done; chgrp vpnforge-agent /var/log/vpnforge/dns-%i.log && chmod 640 /var/log/vpnforge/dns-%i.log'
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
