#!/bin/sh
# Runs once, on first database initialisation.
#
# Creates the single application role (no password: the database is reachable
# only from an internal Docker network, and only by the API and worker), then
# seeds the first owner account and the gateway machine account.
#
# The owner password and the gateway token are generated here, printed once, and
# never stored in the repository or in any environment file. Only a bcrypt hash
# and a SHA-256 hash respectively reach the database.
set -e

generate() {
    head -c 32 /dev/urandom | base64 | tr -d '\n=+/' | cut -c1-24
}

OWNER_PASSWORD="$(generate)"
GATEWAY_TOKEN="$(generate)$(generate)"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
     -v owner_password="$OWNER_PASSWORD" -v gateway_token="$GATEWAY_TOKEN" <<-'EOSQL'
    -- The only role that ever logs in. No password by design.
    CREATE ROLE api LOGIN;

    GRANT USAGE ON SCHEMA public TO api;
    GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO api;
    GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO api;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO api;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public
        GRANT USAGE, SELECT ON SEQUENCES TO api;

    -- The first human. bcrypt, verifiable by PHP's password_verify().
    INSERT INTO users (username, display_name, password_hash, role)
    VALUES ('owner', 'Owner', crypt(:'owner_password', gen_salt('bf', 12)), 'owner');

    -- The Telegram gateway. No password: it authenticates by a token that never
    -- expires and can be revoked from the admin app.
    INSERT INTO users (username, display_name, role)
    VALUES ('gateway', 'Telegram gateway', 'gateway');

    INSERT INTO sessions (user_id, token_hash, label, expires_at)
    SELECT id, encode(digest(:'gateway_token', 'sha256'), 'hex'), 'bootstrap token', NULL
      FROM users WHERE username = 'gateway';
EOSQL

CREDENTIALS="owner password : ${OWNER_PASSWORD}
gateway token  : ${GATEWAY_TOKEN}"

echo ""
echo "=============================================================="
echo " Initial credentials (shown once)"
echo "--------------------------------------------------------------"
echo "${CREDENTIALS}"
echo "--------------------------------------------------------------"
echo " owner password -> sign in to the admin app as user 'owner'"
echo " gateway token  -> Apps Script property API_TOKEN"
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
