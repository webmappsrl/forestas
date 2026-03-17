#!/bin/bash
set -e

BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
RED="\033[0;31m"
RESET="\033[0m"

step() { echo -e "\n${BOLD}▶ $1${RESET}"; }
ok()   { echo -e "${GREEN}✓ $1${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $1${RESET}"; }

echo -e "${BOLD}Laravel PostGIS Boilerplate — Setup${RESET}"
echo "────────────────────────────────────"

# ─── auth.json ───────────────────────────────────────────────────────────────
step "Nova credentials (nova.laravel.com)"

if [ -f auth.json ]; then
    ok "auth.json già presente, skip."
else
    echo -n "  Email: "
    read -r NOVA_EMAIL
    echo -n "  License key: "
    read -r -s NOVA_KEY
    echo

    cat > auth.json <<EOF
{
    "http-basic": {
        "nova.laravel.com": {
            "username": "${NOVA_EMAIL}",
            "password": "${NOVA_KEY}"
        }
    }
}
EOF
    ok "auth.json creato."
fi

# ─── .env ────────────────────────────────────────────────────────────────────
step ".env"

if [ -f .env ]; then
    warn ".env già presente, skip."
else
    echo -n "  APP_NAME (default: myapp): "
    read -r APP_NAME
    APP_NAME=${APP_NAME:-myapp}
    echo -n "  DB_DATABASE (default: ${APP_NAME}): "
    read -r DB_DATABASE
    DB_DATABASE=${DB_DATABASE:-${APP_NAME}}
    echo -n "  DB_USERNAME (default: ${APP_NAME}): "
    read -r DB_USERNAME
    DB_USERNAME=${DB_USERNAME:-${APP_NAME}}
    echo -n "  DB_PASSWORD (default: ${APP_NAME}): "
    read -r -s DB_PASSWORD
    echo
    DB_PASSWORD=${DB_PASSWORD:-${APP_NAME}}
    DOCKER_PROJECT_DIR_NAME=$(basename "$PWD")
    export APP_NAME DOCKER_PROJECT_DIR_NAME DB_DATABASE DB_USERNAME DB_PASSWORD
    envsubst '${APP_NAME} ${DOCKER_PROJECT_DIR_NAME} ${DB_DATABASE} ${DB_USERNAME} ${DB_PASSWORD}' < .env-example > .env
    ok ".env creato con APP_NAME=${APP_NAME}, DB_DATABASE=${DB_DATABASE}."
fi

# ─── submodule ───────────────────────────────────────────────────────────────
step "Git submodule (wm-package)"
git submodule update --init --recursive
ok "Submodule aggiornato."

# ─── compose file ────────────────────────────────────────────────────────────
step "Ambiente Docker"
echo "  Quale compose file vuoi usare?"
echo "  1) local.compose.yml   — standalone con php artisan serve (default)"
echo "  2) develop.compose.yml — con nginx/proxy"
echo -n "  Scelta [1/2]: "
read -r COMPOSE_CHOICE
case "$COMPOSE_CHOICE" in
    2) COMPOSE_FILE="develop.compose.yml" ;;
    *) COMPOSE_FILE="local.compose.yml" ;;
esac
ok "Compose file: ${COMPOSE_FILE}"

# ─── docker infra ────────────────────────────────────────────────────────────
step "Avvio servizi infrastrutturali (db, redis, elasticsearch)"

docker compose -f "$COMPOSE_FILE" up -d db redis elasticsearch
ok "Servizi avviati."

# ─── composer install ────────────────────────────────────────────────────────
step "composer install"
APP_NAME=$(grep "^APP_NAME=" .env | cut -d= -f2)
DIR_NAME=$(grep "^DOCKER_PROJECT_DIR_NAME=" .env | cut -d= -f2 || basename "$PWD")

docker run --rm \
    --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')_default" \
    -v "$PWD:/var/www/html/${DIR_NAME}" \
    -v "$(dirname "$PWD")/wm-package:/var/www/html/wm-package" \
    -w "/var/www/html/${DIR_NAME}" \
    wm-phpfpm:8.4 \
    composer install --no-interaction --prefer-dist
ok "Dipendenze installate."

# ─── artisan setup ───────────────────────────────────────────────────────────
step "php artisan key:generate"
docker run --rm \
    --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')_default" \
    -v "$PWD:/var/www/html/${DIR_NAME}" \
    -v "$(dirname "$PWD")/wm-package:/var/www/html/wm-package" \
    -w "/var/www/html/${DIR_NAME}" \
    wm-phpfpm:8.4 \
    php artisan key:generate
ok "APP_KEY generata."

step "Publish migrations (wm-package)"
docker run --rm \
    --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')_default" \
    -v "$PWD:/var/www/html/${DIR_NAME}" \
    -v "$(dirname "$PWD")/wm-package:/var/www/html/wm-package" \
    -w "/var/www/html/${DIR_NAME}" \
    wm-phpfpm:8.4 \
    php artisan vendor:publish --tag=wm-package-migrations --no-interaction
ok "Migrations pubblicate."

step "php artisan migrate"
docker run --rm \
    --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')_default" \
    -v "$PWD:/var/www/html/${DIR_NAME}" \
    -v "$(dirname "$PWD")/wm-package:/var/www/html/wm-package" \
    -w "/var/www/html/${DIR_NAME}" \
    wm-phpfpm:8.4 \
    php artisan migrate --force
ok "Database migrato."

step "Creazione ruoli base"
docker run --rm \
    --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')_default" \
    -v "$PWD:/var/www/html/${DIR_NAME}" \
    -v "$(dirname "$PWD")/wm-package:/var/www/html/wm-package" \
    -w "/var/www/html/${DIR_NAME}" \
    wm-phpfpm:8.4 \
    php artisan tinker --execute="
foreach (['Administrator', 'Editor', 'Validator', 'Guest'] as \$name) {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => \$name, 'guard_name' => 'web']);
}
echo 'Ruoli creati.' . PHP_EOL;
"
ok "Ruoli creati."

# ─── utente admin ─────────────────────────────────────────────────────────────
step "Creazione utente Administrator"
echo -n "  Nome: "
read -r ADMIN_NAME
echo -n "  Email: "
read -r ADMIN_EMAIL
echo -n "  Password: "
read -r -s ADMIN_PASSWORD
echo

docker run --rm \
    --network "$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')_default" \
    -v "$PWD:/var/www/html/${DIR_NAME}" \
    -v "$(dirname "$PWD")/wm-package:/var/www/html/wm-package" \
    -w "/var/www/html/${DIR_NAME}" \
    -e "ADMIN_NAME=${ADMIN_NAME}" \
    -e "ADMIN_EMAIL=${ADMIN_EMAIL}" \
    -e "ADMIN_PASSWORD=${ADMIN_PASSWORD}" \
    wm-phpfpm:8.4 \
    php artisan tinker --execute='
$user = \App\Models\User::create([
    "name" => getenv("ADMIN_NAME"),
    "email" => getenv("ADMIN_EMAIL"),
    "password" => bcrypt(getenv("ADMIN_PASSWORD")),
    "email_verified_at" => now(),
]);
$user->assignRole("Administrator");
echo "Utente creato: " . $user->email . PHP_EOL;
'
ok "Utente Administrator creato."

# ─── avvio completo ──────────────────────────────────────────────────────────
step "Avvio tutti i servizi"
docker compose -f "$COMPOSE_FILE" up -d
ok "Stack completo avviato."

# ─── icons.json su MinIO ─────────────────────────────────────────────────────
step "Upload icons.json su MinIO"
MINIO_PORT=$(grep "^FORWARD_MINIO_PORT=" .env | cut -d= -f2 || echo "9000")
MINIO_ALIAS="local"
MINIO_BUCKET="wmfe"
MINIO_PATH="${APP_NAME}/json/icons.json"

echo "  Attendo MinIO..."
until curl -s -f "http://localhost:${MINIO_PORT}/minio/health/live" > /dev/null 2>&1; do
    sleep 2
done

NETWORK="$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')_default"

docker run --rm --network "$NETWORK" -v "$PWD/resources/defaults:/defaults" \
    --entrypoint /bin/sh minio/mc \
    -c "mc alias set ${MINIO_ALIAS} http://minio-${APP_NAME}:9000 laravel laravelminio --api S3v4 \
     && mc mb --ignore-existing ${MINIO_ALIAS}/${MINIO_BUCKET} \
     && mc cp /defaults/icons.json ${MINIO_ALIAS}/${MINIO_BUCKET}/${MINIO_PATH}"
ok "icons.json caricato su MinIO (${MINIO_BUCKET}/${MINIO_PATH})."

# ─── xdebug ──────────────────────────────────────────────────────────────────
step "Xdebug"
echo -n "  Vuoi installare e attivare xdebug? [y/N]: "
read -r XDEBUG
if [[ "$XDEBUG" =~ ^[Yy]$ ]]; then
    bash docker/configs/phpfpm/init-xdebug.sh
    ok "Xdebug attivato."
else
    ok "Xdebug saltato."
fi

echo ""
echo -e "${GREEN}${BOLD}Setup completato!${RESET}"
echo "  Nova:    http://localhost:${DOCKER_SERVE_PORT:-8000}/nova"
echo "  Horizon: http://localhost:${DOCKER_SERVE_PORT:-8000}/horizon"
echo "  Kibana:  http://localhost:${DOCKER_KIBANA_PORT:-5601}"
echo "  MinIO:   http://localhost:${FORWARD_MINIO_CONSOLE_PORT:-8900}"
echo ""
echo -e "${YELLOW}Crea il primo utente admin:${RESET}"
echo "  docker exec php-\${APP_NAME} php artisan nova:user"
