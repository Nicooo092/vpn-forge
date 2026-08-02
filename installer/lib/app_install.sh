#!/bin/bash
#
# Clones the vpn-forge repo itself (not a release tarball -- this is a
# fresh project with no tagged releases yet; pinning to one can come later
# once it's proven) and sets up the Laravel app.

install_app() {
  output "Downloading vpn-forge..."
  if [ -d "${APP_DIR}/.git" ]; then
    (cd "$APP_DIR" && git pull --ff-only)
  else
    rm -rf "$APP_DIR"
    git clone --depth 1 "$VPNFORGE_REPO_URL" "$APP_DIR"
  fi

  output "Installing PHP dependencies via Composer (this can take a few minutes)..."
  (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction)

  output "Configuring the environment..."
  cd "$APP_DIR" || exit 1
  [ -f .env ] || cp .env.example .env
  php artisan key:generate --force -n

  # .env.example ships Laravel's development defaults, and nothing here used
  # to override them -- so a public install ran with APP_DEBUG=true, printing
  # a full stack trace (paths, environment, frequently the database password)
  # to anyone able to trigger an error.
  ensure_env_key .env APP_ENV production
  ensure_env_key .env APP_DEBUG false
  ensure_env_key .env APP_URL "${SCHEME}://${APP_HOST}"
  ensure_env_key .env APP_TIMEZONE "${TIMEZONE}"
  ensure_env_key .env APP_LOCALE "${APP_LANGUAGE}"
  ensure_env_key .env DB_CONNECTION mariadb
  ensure_env_key .env DB_HOST 127.0.0.1
  ensure_env_key .env DB_PORT 3306
  ensure_env_key .env DB_DATABASE "${DB_NAME}"
  ensure_env_key .env DB_USERNAME "${DB_USER}"
  ensure_env_key .env DB_PASSWORD "${DB_PASSWORD}"
  ensure_env_key .env CACHE_STORE database
  ensure_env_key .env SESSION_DRIVER database
  ensure_env_key .env QUEUE_CONNECTION database

  # Mark the session cookie Secure whenever the panel is served over HTTPS, so
  # the browser never sends it back over a plain-HTTP request. On a bare-IP
  # HTTP install this stays false, since a Secure cookie the browser refuses to
  # send would lock the operator out.
  if [ "${SCHEME}" = "https" ]; then
    ensure_env_key .env SESSION_SECURE_COOKIE true
  else
    ensure_env_key .env SESSION_SECURE_COOKIE false
  fi
  php artisan config:clear

  output "Running database migrations..."
  php artisan migrate --force

  # The traffic_logs/services/service_users tables only exist from this
  # point on -- grant the capture agent's table-level privileges now.
  grant_agent_table_privileges

  output "Creating the admin account..."
  php artisan make:filament-user --name="${ADMIN_USERNAME}" --email="${ADMIN_EMAIL}" --password="${ADMIN_PASSWORD}"

  output "Setting file permissions..."
  chmod -R 755 "$APP_DIR"/storage/* "$APP_DIR"/bootstrap/cache/
  chown -R www-data:www-data "$APP_DIR"

  output "Installing the scheduler cron job..."
  local cron_line="* * * * * php ${APP_DIR}/artisan schedule:run >> /dev/null 2>&1"
  local existing
  existing=$(crontab -u www-data -l 2>/dev/null | grep -v 'schedule:run' || true)
  {
    [ -n "$existing" ] && printf '%s\n' "$existing"
    echo "$cron_line"
  } | crontab -u www-data -

  if ! crontab -u www-data -l 2>/dev/null | grep -q 'schedule:run'; then
    error "Failed to install the www-data cron job for the scheduler."
    exit 1
  fi
}
