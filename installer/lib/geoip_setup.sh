#!/bin/bash
#
# Optional GeoIP enrichment. DB-IP publishes free, CC-BY "lite" country and ASN
# databases in MaxMind's .mmdb format every month at
# https://download.db-ip.com/free/ -- there is no apt package, so the current
# month's files are downloaded here and refreshed monthly by the scheduler
# (vpnforge:geoip-refresh -> RefreshGeoIpDatabases on the privileged worker).
# The feature is entirely optional: if a download fails the panel simply runs
# without geolocation, so nothing here is fatal.

install_geoip_databases() {
  local geo_dir="/etc/vpnforge/geoip"

  output "Setting up optional GeoIP (DB-IP Lite) databases..."
  mkdir -p "$geo_dir"
  # World-readable: the mmdb files are public data (CC-BY), and www-data (the
  # panel) reads them directly. The worker owns the directory so its monthly
  # refresh job can replace the files in place.
  chown vpnforge-worker:vpnforge-worker "$geo_dir"
  chmod 755 "$geo_dir"

  fetch_dbip_dataset "dbip-country-lite" "${geo_dir}/dbip-country-lite.mmdb"
  fetch_dbip_dataset "dbip-asn-lite" "${geo_dir}/dbip-asn-lite.mmdb"

  chown vpnforge-worker:vpnforge-worker "$geo_dir"/*.mmdb 2>/dev/null || true
  chmod 644 "$geo_dir"/*.mmdb 2>/dev/null || true
}

# fetch_dbip_dataset <dataset> <target_path>
# Tries the current month, then the previous one (the new file typically lands a
# day or two into each month). Non-fatal: a failure leaves the feature off until
# the next scheduled refresh.
fetch_dbip_dataset() {
  local dataset=$1
  local target=$2
  local base="https://download.db-ip.com/free"
  local month url tmp

  for month in "$(date -u +%Y-%m)" "$(date -u -d '1 month ago' +%Y-%m)"; do
    url="${base}/${dataset}-${month}.mmdb.gz"
    tmp="$(mktemp)"
    if curl -fsSL "$url" -o "${tmp}.gz" 2>/dev/null && gunzip -c "${tmp}.gz" >"$tmp" 2>/dev/null && [ -s "$tmp" ]; then
      mv "$tmp" "$target"
      rm -f "${tmp}.gz"
      output "  Installed ${dataset} (${month})."
      return 0
    fi
    rm -f "$tmp" "${tmp}.gz"
  done

  warning "  Could not download ${dataset}; GeoIP enrichment stays off until the next monthly refresh."
  return 0
}
