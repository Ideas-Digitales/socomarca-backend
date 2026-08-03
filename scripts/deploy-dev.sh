#!/usr/bin/env bash
#
# Local development "deploy".
#
# Mirrors the deploy job of .github/workflows/deploy.yml against the containers
# defined in compose.yml, minus everything that only makes sense in a remote
# environment:
#
#   - no image build / GHCR push / pull (compose builds the image locally)
#   - no SSH, no .env generation from GitHub secrets
#   - no `php artisan optimize`: caching config, routes and views in development
#     only hides the changes you just made
#   - no `docker image prune`
#   - no worker service (compose.yml does not define one)
#
# What it does: bring the containers up, wait until they are ready, run the
# migrations and the seeders, then health check and report status.
#
# POSIX-compatible on purpose: runs identically under sh, bash and zsh, either
# as `./scripts/deploy-dev.sh`, `bash scripts/deploy-dev.sh` or
# `zsh scripts/deploy-dev.sh`.

set -eu

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

FRESH=0
SEED=1
SEEDER=""
CLEAR_CACHE=0
SKIP_HEALTH=0
DRY_RUN=0
DB_TIMEOUT=60
APP_SERVICE="app"
DB_SERVICE="db"

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

if [ -t 1 ]; then
    C_RESET=$(printf '\033[0m')
    C_INFO=$(printf '\033[1;34m')
    C_OK=$(printf '\033[1;32m')
    C_WARN=$(printf '\033[1;33m')
    C_ERR=$(printf '\033[1;31m')
else
    C_RESET=""; C_INFO=""; C_OK=""; C_WARN=""; C_ERR=""
fi

info() { printf '%s==>%s %s\n' "$C_INFO" "$C_RESET" "$1"; }
ok()   { printf '%s  ✓%s %s\n' "$C_OK" "$C_RESET" "$1"; }
warn() { printf '%s  !%s %s\n' "$C_WARN" "$C_RESET" "$1" >&2; }
die()  { printf '%s  ✗%s %s\n' "$C_ERR" "$C_RESET" "$1" >&2; exit 1; }

usage() {
    cat <<'EOF'
Usage: scripts/deploy-dev.sh [options]

Brings the local compose stack up and runs migrations + seeders, the same way
the deploy workflow does on the server (without building or caching).

Options:
  -f, --fresh          Drop every table and re-run all migrations
                       (php artisan migrate:fresh). DESTROYS local data.
  -n, --no-seed        Run migrations only, skip the seeders.
  -s, --seeder=CLASS   Run only this seeder class after migrating,
                       e.g. --seeder=RolesAndPermissionsSeeder
  -c, --clear-cache    Run `php artisan optimize:clear` after migrating.
                       Useful if you ever cached config locally.
      --skip-health    Do not perform the HTTP health check.
      --timeout=SECS   Seconds to wait for the database (default: 60).
      --dry-run        Print the commands instead of running them.
  -h, --help           Show this help.

Examples:
  scripts/deploy-dev.sh
  scripts/deploy-dev.sh --fresh
  scripts/deploy-dev.sh --no-seed
  scripts/deploy-dev.sh --seeder=RolesAndPermissionsSeeder
EOF
}

# ---------------------------------------------------------------------------
# Argument parsing (long and short flags, `--opt=value` and `--opt value`)
# ---------------------------------------------------------------------------

while [ $# -gt 0 ]; do
    case "$1" in
        -f|--fresh)        FRESH=1 ;;
        -n|--no-seed)      SEED=0 ;;
        -c|--clear-cache)  CLEAR_CACHE=1 ;;
        --skip-health)     SKIP_HEALTH=1 ;;
        --dry-run)         DRY_RUN=1 ;;
        -s|--seeder)       shift; [ $# -gt 0 ] || die "--seeder requires a class name"; SEEDER="$1" ;;
        --seeder=*)        SEEDER="${1#*=}" ;;
        --timeout)         shift; [ $# -gt 0 ] || die "--timeout requires a value"; DB_TIMEOUT="$1" ;;
        --timeout=*)       DB_TIMEOUT="${1#*=}" ;;
        -h|--help)         usage; exit 0 ;;
        *)                 usage >&2; die "Unknown option: $1" ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
# Run from the project root regardless of where the script was invoked
# ---------------------------------------------------------------------------

SCRIPT_PATH="$0"
# zsh does not resolve $0 through symlinks either, so follow them by hand.
while [ -L "$SCRIPT_PATH" ]; do
    SCRIPT_PATH=$(readlink "$SCRIPT_PATH")
done
PROJECT_ROOT=$(cd -- "$(dirname -- "$SCRIPT_PATH")/.." && pwd)
cd "$PROJECT_ROOT"

# ---------------------------------------------------------------------------
# Command runner
# ---------------------------------------------------------------------------

run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '    %s\n' "$*"
        return 0
    fi
    "$@"
}

# artisan <args...> — run an artisan command inside the app container.
# -T keeps it usable from non-interactive contexts such as a VS Code task.
artisan() {
    run docker compose exec -T "$APP_SERVICE" php artisan "$@"
}

# ---------------------------------------------------------------------------
# 1. Preflight
# ---------------------------------------------------------------------------

info "Checking requirements"

command -v docker >/dev/null 2>&1 || die "docker is not installed or not in PATH"
docker compose version >/dev/null 2>&1 || die "the 'docker compose' plugin is not available"
[ -f compose.yml ] || die "compose.yml not found in $PROJECT_ROOT"

if [ ! -f .env ]; then
    warn ".env not found"
    if [ -f .env.example ]; then
        info "Creating .env from .env.example"
        run cp .env.example .env
    else
        die ".env.example not found either; cannot continue"
    fi
fi
ok "docker, compose and .env are in place"

# ---------------------------------------------------------------------------
# 2. Start containers (workflow: "Clean and deploy containers")
# ---------------------------------------------------------------------------

info "Starting containers"
run docker compose up -d --remove-orphans
ok "containers up"

# ---------------------------------------------------------------------------
# 3. Wait for the database — the workflow relies on the server already being
#    warm; locally the db may still be initialising.
# ---------------------------------------------------------------------------

info "Waiting for the database (timeout: ${DB_TIMEOUT}s)"
if [ "$DRY_RUN" -eq 1 ]; then
    printf '    %s\n' "docker compose exec -T $DB_SERVICE pg_isready -q  # retried until ready"
else
    waited=0
    until docker compose exec -T "$DB_SERVICE" pg_isready -q >/dev/null 2>&1; do
        waited=$((waited + 2))
        if [ "$waited" -ge "$DB_TIMEOUT" ]; then
            docker compose logs --tail=50 "$DB_SERVICE" >&2 || true
            die "database not ready after ${DB_TIMEOUT}s"
        fi
        sleep 2
    done
    ok "database accepting connections"
fi

# ---------------------------------------------------------------------------
# 4. Generate APP_KEY if the .env we just copied has none
# ---------------------------------------------------------------------------

if [ "$DRY_RUN" -eq 0 ] && ! grep -qE '^APP_KEY=.+' .env; then
    info "Generating application key"
    artisan key:generate --ansi
fi

# ---------------------------------------------------------------------------
# 5. Migrate + seed (workflow: `php artisan migrate --force --seed`)
# ---------------------------------------------------------------------------

if [ "$FRESH" -eq 1 ]; then
    warn "--fresh drops every table in the development database"
    info "Running migrate:fresh"
    if [ "$SEED" -eq 1 ] && [ -z "$SEEDER" ]; then
        artisan migrate:fresh --seed
    else
        artisan migrate:fresh
    fi
else
    info "Running migrations"
    if [ "$SEED" -eq 1 ] && [ -z "$SEEDER" ]; then
        artisan migrate --seed
    else
        artisan migrate
    fi
fi
ok "migrations applied"

if [ -n "$SEEDER" ]; then
    info "Seeding $SEEDER"
    artisan db:seed --class="$SEEDER"
    ok "$SEEDER done"
elif [ "$SEED" -eq 1 ]; then
    ok "seeders done"
else
    warn "seeders skipped (--no-seed)"
fi

# ---------------------------------------------------------------------------
# 6. Caches — the workflow runs `php artisan optimize` here. In development
#    that is deliberately skipped; clearing is available on request.
# ---------------------------------------------------------------------------

if [ "$CLEAR_CACHE" -eq 1 ]; then
    info "Clearing caches"
    artisan optimize:clear
    ok "caches cleared"
else
    info "Skipping cache warm-up (not wanted in development)"
fi

# ---------------------------------------------------------------------------
# 7. Health check (workflow: "Health check")
#
# The workflow probes http://localhost:80/up. Locally Caddy answers port 80
# with a 308 to HTTPS and serves a self-signed certificate, so the probe goes
# straight to HTTPS and skips certificate verification.
# ---------------------------------------------------------------------------

HEALTH_CMD="wget --spider -q --no-check-certificate https://localhost/up"

if [ "$SKIP_HEALTH" -eq 1 ]; then
    warn "health check skipped (--skip-health)"
elif [ "$DRY_RUN" -eq 1 ]; then
    printf '    %s\n' "docker compose exec -T $APP_SERVICE $HEALTH_CMD"
else
    info "Verifying HTTP response (internal)"
    # shellcheck disable=SC2086
    if docker compose exec -T "$APP_SERVICE" $HEALTH_CMD; then
        ok "app responding on /up"
    else
        docker compose logs --tail=50 "$APP_SERVICE" >&2 || true
        die "health check failed"
    fi
fi

# ---------------------------------------------------------------------------
# 8. Status (workflow: "Containers status")
# ---------------------------------------------------------------------------

info "Containers status"
run docker compose ps

printf '\n%sDevelopment deploy finished.%s App available at http://localhost:8080\n' "$C_OK" "$C_RESET"
