#!/usr/bin/env bash
set -e

psql -vvv --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
	CREATE DATABASE socomarca_backend_testing;
EOSQL
