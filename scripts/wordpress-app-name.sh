# Legge APP_NAME dal .env di forestas e lo mette in APP_NAME. Da caricare con «source» dagli script di
# WordPress (wordpress-up.sh, wordpress-reset.sh) dopo essersi spostati nella radice del repo (oc:8717).
# sed e non «grep | head»: con pipefail grep senza risultati (o head che chiude prima) farebbe uscire lo
# script senza i messaggi qui sotto.
if [ ! -f .env ]; then
    echo "Il .env di forestas non c'è: crealo da .env-example prima di avviare WordPress" >&2
    exit 1
fi
APP_NAME=$(sed -n 's/^APP_NAME=//p' .env | sed -n 1p | tr -d '"'"'")
if [ -z "$APP_NAME" ]; then
    echo "APP_NAME non trovato nel .env di forestas" >&2
    exit 1
fi
