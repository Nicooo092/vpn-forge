[Unit]
Description=vpn-forge dnsmasq instance for %i
After=network.target

[Service]
Type=simple
ExecStart=/usr/sbin/dnsmasq --keep-in-foreground --conf-file=/etc/vpnforge/dnsmasq/%i.conf
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
