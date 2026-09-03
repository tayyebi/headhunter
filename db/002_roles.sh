#!/bin/sh
# Creates the login roles that ARE this application's users.
# Runs once, on first database initialisation.
#
# No password is read from the environment and none is stored in the repository:
# the two that need one are generated here and written to ./secrets on the host
# (mounted at /bootstrap). Read them once, sign in, and change them from the
# Account screen — that runs ALTER ROLE against the role's own password.
set -e

generate() {
    head -c 32 /dev/urandom | base64 | tr -d '\n=+/' | cut -c1-24
}

OWNER_PASSWORD="$(generate)"
GATEWAY_PASSWORD="$(generate)"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    -- Group role. All table privileges hang off this; humans are members of it.
    CREATE ROLE hh_admin NOLOGIN;

    -- The bootstrap headhunter. CREATEROLE lets it add colleagues via POST /admins.
    CREATE ROLE hh_owner LOGIN CREATEROLE PASSWORD '${OWNER_PASSWORD}';
    GRANT hh_admin TO hh_owner WITH ADMIN OPTION;

    -- The background worker. No password: pg_hba.conf trusts it, and only this
    -- compose network can reach the database.
    CREATE ROLE hh_worker LOGIN;
    GRANT hh_admin TO hh_worker;

    -- The Telegram gateway (Google Apps Script). Deliberately narrow.
    CREATE ROLE bot_gateway LOGIN PASSWORD '${GATEWAY_PASSWORD}';

    GRANT USAGE ON SCHEMA public TO hh_admin, bot_gateway;

    -- Humans: everything.
    GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO hh_admin;
    GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO hh_admin;

    -- Gateway: intake, plus fetching the file of a delivery it was pushed.
    -- It has NO privilege on settings or runs at all, so those 403 at the database.
    GRANT SELECT, INSERT ON candidates TO bot_gateway;
    GRANT UPDATE (display_name, phone, updated_at) ON candidates TO bot_gateway;
    GRANT SELECT, INSERT ON resumes TO bot_gateway;
    GRANT SELECT (id, candidate_id, kind, body, file_path, file_name, status)
        ON deliveries TO bot_gateway;
    GRANT USAGE, SELECT ON SEQUENCE candidates_id_seq, resumes_id_seq TO bot_gateway;
EOSQL

CREDENTIALS="hh_owner     ${OWNER_PASSWORD}
bot_gateway  ${GATEWAY_PASSWORD}"

echo ""
echo "=============================================================="
echo " Initial database credentials (shown once)"
echo "--------------------------------------------------------------"
echo "${CREDENTIALS}"
echo "--------------------------------------------------------------"
echo " hh_owner    -> sign in to the admin app with this"
echo " bot_gateway -> put in the Apps Script property API_PASS"
echo "=============================================================="
echo ""

# Docker creates a missing bind-mount directory owned by root, and this script
# runs as the postgres user, so the write can legitimately fail. It must never
# abort initialisation: the credentials are in the log above either way.
if [ -d /bootstrap ] && printf '%s\n' "${CREDENTIALS}" > /bootstrap/initial-credentials.txt 2>/dev/null; then
    chmod 600 /bootstrap/initial-credentials.txt 2>/dev/null || true
    echo "Also written to ./data/secrets/initial-credentials.txt"
else
    echo "Could not write ./data/secrets/initial-credentials.txt - copy the values above now."
fi
