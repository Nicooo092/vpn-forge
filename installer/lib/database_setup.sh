#!/bin/bash
#
# Two separate MariaDB credentials, deliberately:
#   - $DB_USER: full access, used by the Laravel app (migrations, all
#     tables).
#   - $AGENT_DB_USER: INSERT/SELECT on traffic_logs only, plus SELECT on
#     services/service_users for its tunnel-IP -> user lookups. A
#     compromised capture agent (it's the one process parsing untrusted
#     network traffic) can't touch anything else this way.
# ALTER USER runs even if an account already exists from a prior run, so
# re-running this installer always leaves the password matching what gets
# written into .env / the agent's config.yml right after.

setup_database() {
  output "Creating the database and app user..."
  mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
  mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';"
  mariadb -e "ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';"
  mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"

  output "Creating the least-privileged capture agent database user..."
  mariadb -e "CREATE USER IF NOT EXISTS '${AGENT_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${AGENT_DB_PASSWORD}';"
  mariadb -e "ALTER USER '${AGENT_DB_USER}'@'127.0.0.1' IDENTIFIED BY '${AGENT_DB_PASSWORD}';"
  # Tables don't exist yet at this point (migrations haven't run) --
  # grants are re-applied after migrate in app_install.sh, once the
  # traffic_logs/services/service_users tables actually exist.

  mariadb -e "FLUSH PRIVILEGES;"
}

grant_agent_table_privileges() {
  output "Granting the capture agent's table-level privileges..."
  mariadb -e "GRANT INSERT, SELECT ON \`${DB_NAME}\`.traffic_logs TO '${AGENT_DB_USER}'@'127.0.0.1';"
  mariadb -e "GRANT SELECT ON \`${DB_NAME}\`.services TO '${AGENT_DB_USER}'@'127.0.0.1';"
  mariadb -e "GRANT SELECT ON \`${DB_NAME}\`.service_users TO '${AGENT_DB_USER}'@'127.0.0.1';"
  mariadb -e "FLUSH PRIVILEGES;"
}
