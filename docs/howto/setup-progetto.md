# Setup del progetto

Lo script `scripts/install.sh` esegue l'installazione guidata completa. Sotto la sequenza manuale
equivalente, utile quando qualcosa va storto a metà.

## 1. Configurare `.env`

```bash
cp .env-example .env
# Modificare: APP_NAME, DOCKER_PHP_PORT, DOCKER_PROJECT_DIR_NAME
```

## 2. Avviare Docker

```bash
bash docker/init-docker.sh
```

Tre file compose, con scopi distinti:

| File | Scopo | Comando |
|---|---|---|
| `compose.yml` | base condivisa (prod), non si usa direttamente | `docker compose up -d` |
| `develop.compose.yml` | sviluppo locale con nginx/proxy; aggiunge minio, mailpit | `docker compose -f develop.compose.yml up -d` |
| `local.compose.yml` | sviluppo locale standalone con `php artisan serve`; aggiunge scout-init, kibana, laravel server | `docker compose -f local.compose.yml up -d` |

I container usano il trattino come separatore: `php-forestas`, `postgres-forestas`,
`horizon-forestas`, `minio-forestas`.

## 3. Dipendenze e configurazione Laravel

```bash
docker exec -it php-forestas composer install
docker exec -it php-forestas php artisan key:generate
docker exec -it php-forestas php artisan optimize
docker exec -it php-forestas php artisan vendor:publish --tag=wm-package-migrations
docker exec -it php-forestas php artisan migrate
```

## 4. Ambiente di test

`.env.testing` **non è versionato** (contiene chiavi): lo crea `install.sh`, oppure si fa a mano dal
modello.

```bash
cp .env.testing-example .env.testing
docker exec -it php-forestas php artisan key:generate --env=testing --quiet
docker exec -it php-forestas php artisan jwt:secret --env=testing --force --quiet

# Database di test, una volta per ambiente
docker exec -i postgres-forestas psql -U ${DB_USERNAME} -d postgres -c "CREATE DATABASE forestas_testing;"
docker exec -i postgres-forestas psql -U ${DB_USERNAME} -d forestas_testing -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

## 5. Ruoli base e primo utente

I ruoli previsti sono `Administrator`, `Editor`, `Validator`, `Guest`, `Contributor`, `Sus`.

```bash
docker exec -it php-forestas php artisan tinker --execute="
foreach (['Administrator', 'Editor', 'Validator', 'Guest', 'Contributor', 'Sus'] as \$name) {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => \$name, 'guard_name' => 'web']);
}
"
docker exec -it php-forestas php artisan nova:user
```

## MinIO

Disponibile negli ambienti di sviluppo per simulare S3. Endpoint
`http://localhost:${FORWARD_MINIO_PORT}` (default 9000), console sulla 8900, credenziali
`laravel` / `laravelminio` (`develop.compose.yml`), bucket `wmfe`. Il sistema di icone globale è
gestito da `GlobalFileHelper` del package, che tiene aggiornato `icons.json` su MinIO.

## Elasticsearch

Variabili `.env` rilevanti:

```
ELASTICSEARCH_HOST=elasticsearch:9200
ELASTICSEARCH_USER=elastic
ELASTICSEARCH_PASSWORD=changeme
ELASTICSEARCH_SSL_VERIFICATION=false
DOCKER_KIBANA_PORT=5601
```
