#!/bin/bash

configure_nginx() {
  output "Configuring nginx..."
  cat >/etc/nginx/sites-available/vpnforge.conf <<'NGINXEOF'
server {
    listen 80;
    server_name __APP_HOST__;

    root __APP_DIR__/public;
    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    access_log off;
    error_log /var/log/nginx/vpnforge-error.log error;

    client_max_body_size 20m;

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINXEOF

  sed -i "s|__APP_HOST__|${APP_HOST}|" /etc/nginx/sites-available/vpnforge.conf
  sed -i "s|__APP_DIR__|${APP_DIR}|" /etc/nginx/sites-available/vpnforge.conf

  rm -f /etc/nginx/sites-enabled/default
  ln -sf /etc/nginx/sites-available/vpnforge.conf /etc/nginx/sites-enabled/vpnforge.conf
  nginx -t
  systemctl reload-or-restart nginx
}

configure_ssl() {
  [ "$SCHEME" = "https" ] || return 0

  output "Requesting a Let's Encrypt certificate for ${APP_HOST}..."
  apt_install certbot python3-certbot-nginx
  certbot --nginx -d "${APP_HOST}" --non-interactive --agree-tos -m "${ADMIN_EMAIL}" --redirect
}
