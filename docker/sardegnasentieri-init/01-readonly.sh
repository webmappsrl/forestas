#!/bin/bash
# Revoca i permessi di scrittura all'utente readonly sul db sardegnasentieri
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    REVOKE CREATE ON SCHEMA public FROM readonly;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM readonly;
    GRANT CONNECT ON DATABASE sardegnasentieri TO readonly;
    GRANT USAGE ON SCHEMA public TO readonly;
    GRANT SELECT ON ALL TABLES IN SCHEMA public TO readonly;
EOSQL
