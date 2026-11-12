{{--
    Copia della view `scramble::docs` del package, che Laravel fa vincere su
    quella di vendor/ senza bisogno di toccare `config/scramble.php`.

    L'unica aggiunta e' in fondo allo <script> che Scramble usa gia' per
    l'header XSRF: il wrapper di `fetch` guarda anche le risposte, e quando
    ne vede una con `access_token` tiene il token da parte e accende in alto
    a destra un bottone che lo copia da solo. Serve perche' il «copia» di
    Stoplight Elements copia sempre l'esempio intero — l'oggetto con
    `access_token`, `token_type` e `expires_in` — e per incollare il token
    nel campo Authorization delle altre chiamate bisognava selezionarlo a
    mano dentro il JSON ad ogni login.

    Il bottone e' nostro e non dipende dal DOM interno di Elements: un loro
    aggiornamento puo' cambiare la pagina senza romperlo. Quando Scramble
    aggiorna la propria view, questa copia va riallineata a mano.
--}}
<!doctype html>
<html lang="en" data-theme="{{ $config->renderer()->get('theme', 'light') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="color-scheme" content="{{ $config->renderer()->get('theme', 'light') }}">
    <title>{{ $config->get('ui.title') ?? config('app.name') . ' - API Docs' }}</title>

    <script src="https://unpkg.com/@stoplight/elements@8.4.2/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements@8.4.2/styles.min.css">

    @include('scramble::dev-tools', ['renderer' => 'elements'])

    <script>
        // Questo <script> sostituisce quello della view originale di Scramble,
        // che faceva la stessa cosa (l'header XSRF) ma ricostruendo gli header
        // della richiesta da capo: `const { headers = new Headers() } = options
        // || {}`. Quando Elements chiama `fetch` senza un `options` con header
        // propri — e' il caso delle chiamate del Try It — quella riga butta via
        // il `Content-Type` generato per il corpo, boundary del multipart
        // compreso. PHP riceve allora un multipart che non sa piu' separare e
        // legge zero campi: la chiamata risponde 422 dicendo che mancano anche
        // i campi che chi prova ha compilato.
        //
        // Qui la richiesta si costruisce invece con `new Request(input, init)`,
        // che unisce i due argomenti nel modo previsto dalla specifica, e gli
        // header si aggiungono su quella, senza toccare il resto.
        const originalFetch = window.fetch;

        window.fetch = async (input, init) => {
            const request = new Request(input, init);

            const getCookieValue = (key) => {
                const cookie = document.cookie.split(';').find((c) => c.trim().startsWith(key));
                return cookie?.split('=')[1];
            };

            // Header XSRF, necessario all'autenticazione a cookie di Sanctum:
            // e' il motivo per cui la view originale avvolge `fetch`.
            const csrfToken = getCookieValue('XSRF-TOKEN');
            if (csrfToken) {
                request.headers.set('X-XSRF-TOKEN', decodeURIComponent(csrfToken));
            }

            return withApplicationToken(request, originalFetch);
        };

        // --- aggiunta locale: il token si applica da solo -------------------
        //
        // Il «copia» di Stoplight Elements copia sempre l'esempio intero, e chi
        // prova le API si ritrova a ritagliare la stringa del token dentro il
        // JSON ad ogni login. Qui il passaggio sparisce: il token dell'ultima
        // risposta di login resta da parte e parte come `Authorization: Bearer
        // ...` sulle chiamate successive del Try It.
        //
        // Il campo «Auth» di Elements e' precompilato con un valore di esempio
        // (`123`), che partirebbe come `Authorization: Bearer 123` e farebbe
        // rispondere 401 ad ogni chiamata. Per questo l'header si sostituisce
        // anche quando c'e' gia', a meno che non contenga un JWT: un token
        // vero, incollato a mano da chi prova, resta quello che vale.
        let withApplicationToken;

        (() => {
            const SUS_PATH = '/api/v1/sus/';
            const AUTH_PATHS = [SUS_PATH + 'auth/login', SUS_PATH + 'auth/refresh'];
            let token = null;

            const readToken = (body) => {
                try {
                    const parsed = typeof body === 'string' ? JSON.parse(body) : body;
                    return parsed && typeof parsed.access_token === 'string' ? parsed.access_token : null;
                } catch (e) {
                    return null;
                }
            };

            // Un JWT ha tre parti separate da punto e comincia per `eyJ`: e' il
            // modo piu' semplice per distinguere un token vero dal valore di
            // esempio del campo, senza decodificarlo.
            const looksLikeJwt = (value) => /^\s*Bearer\s+eyJ[\w-]*\.[\w-]*\./i.test(String(value || ''));

            withApplicationToken = async (request, send) => {
                const isSus = request.url.includes(SUS_PATH);
                const isAuthCall = AUTH_PATHS.some((path) => request.url.includes(path));

                // Le chiamate di autenticazione restano come sono: sono il modo
                // per *ottenere* il token, non per usarlo.
                if (token && isSus && !isAuthCall && !looksLikeJwt(request.headers.get('Authorization'))) {
                    request.headers.set('Authorization', 'Bearer ' + token);
                }

                const response = await send(request);

                // `clone()` perche' il corpo si legge una volta sola: senza,
                // Elements troverebbe la risposta gia' consumata.
                try {
                    if ((response.headers.get('content-type') || '').includes('json')) {
                        const found = readToken(await response.clone().json());
                        if (found) { token = found; showState(); }
                    }
                } catch (e) {
                    // Una risposta non leggibile come JSON non e' un errore:
                    // riguarda questo codice, non la chiamata, che prosegue.
                }

                return response;
            };

            // --- prova del caso completo -----------------------------------
            //
            // La creazione ha 17 campi obbligatori: compilarli a mano nel Try
            // It ad ogni giro, solo per vedere se il canale risponde, non e'
            // ragionevole. Questo bottone manda un modulo gia' pieno — gli
            // stessi valori del `curl` nella descrizione dell'endpoint — con un
            // tracciato di tre punti generato qui.
            //
            // I dati sono palesemente di prova e la domanda che nasce e' una
            // domanda vera: va usato solo su ambienti di prova.
            const SAMPLE = {
                name: 'Sentiero del Monte Arcosu',
                from: 'Case Marganai',
                to: 'Punta Perda Liana',
                distance: '12.5',
                ascent: '640',
                duration_forward: '240',
                duration_backward: '200',
                activities: '["hiking","mtb"]',
                difficulty: '{"hiking":"EE"}',
                existing_signage: 'good_condition',
                proposed_managing_municipalities: '["Domusnovas"]',
                municipality_email: 'protocollo@comune.example.it',
                soggetto_gestore: 'forestas',
                soggetto_rilevatore: 'applicant',
                soggetto_manutentore: 'forestas',
                soggetto_custode_presente: 'false',
                last_survey_date: '2026-10-20',
                maintenance_frequency_months: '12',
            };

            const SAMPLE_GPX = [
                // La dichiarazione XML si compone a pezzi: scritta per intero,
                // il suo segno di apertura aprirebbe un tag PHP dentro questa
                // Blade, e la pagina andrebbe in errore di sintassi.
                '<' + '?xml version="1.0" encoding="UTF-8"?' + '>',
                '<gpx version="1.1" creator="docs" xmlns="http://www.topografix.com/GPX/1/1">',
                '  <trk><name>Tracciato di prova</name><trkseg>',
                '    <trkpt lat="39.3167" lon="8.6333"/>',
                '    <trkpt lat="39.3200" lon="8.6400"/>',
                '    <trkpt lat="39.3250" lon="8.6480"/>',
                '  </trkseg></trk>',
                '</gpx>',
            ].join('\n');

            const panel = document.createElement('div');
            panel.style.cssText = [
                'position:fixed', 'top:44px', 'right:16px', 'z-index:9999',
                'max-width:380px', 'padding:10px 12px', 'border-radius:6px',
                'background:#fff', 'border:1px solid #d9dde3', 'color:#1f2933',
                'font:13px/1.45 ui-sans-serif,system-ui,sans-serif',
                'box-shadow:0 1px 4px rgba(0,0,0,.18)',
            ].join(';');

            const run = document.createElement('button');
            run.type = 'button';
            run.textContent = 'Prova una domanda completa';
            run.style.cssText = [
                'padding:6px 12px', 'border:1px solid #1f6feb', 'border-radius:5px',
                'background:#1f6feb', 'color:#fff', 'cursor:pointer',
                'font:600 12px/1.2 ui-sans-serif,system-ui,sans-serif',
            ].join(';');

            const outcome = document.createElement('pre');
            outcome.style.cssText = [
                'margin:8px 0 0', 'max-height:220px', 'overflow:auto',
                'white-space:pre-wrap', 'word-break:break-word',
                'font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace',
                'color:#3e4c59',
            ].join(';');

            run.addEventListener('click', async () => {
                if (!token) {
                    outcome.textContent = 'Fai prima il login: la prova usa il token di questa pagina.';
                    return;
                }

                run.disabled = true;
                outcome.textContent = 'Invio in corso…';

                const form = new FormData();
                Object.entries(SAMPLE).forEach(([key, value]) => form.append(key, value));
                form.append('geometry', new Blob([SAMPLE_GPX], { type: 'application/gpx+xml' }), 'tracciato-di-prova.gpx');

                try {
                    const response = await window.fetch(SUS_PATH + 'trail-applications', {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: form,
                    });
                    const body = await response.json();
                    outcome.textContent = response.status + ' ' + response.statusText + '\n'
                        + JSON.stringify(body, null, 2)
                        + (response.ok && body.id
                            ? '\n\nL\'id qui sopra si usa nelle due chiamate seguenti.'
                            : '');
                } catch (e) {
                    outcome.textContent = 'La chiamata non è partita: ' + e;
                }

                run.disabled = false;
            });

            panel.appendChild(run);
            panel.appendChild(outcome);

            // Una riga di stato, non un comando: dice se le chiamate stanno
            // partendo autenticate. Senza, chi prova non ha modo di sapere
            // perche' una chiamata risponde 401.
            const badge = document.createElement('div');
            const paint = (text, active) => {
                badge.textContent = text;
                badge.style.cssText = [
                    'position:fixed', 'top:12px', 'right:16px', 'z-index:9999',
                    'padding:6px 12px', 'border-radius:6px',
                    'font:600 12px/1.2 ui-sans-serif,system-ui,sans-serif',
                    'box-shadow:0 1px 4px rgba(0,0,0,.18)', 'pointer-events:none',
                    active ? 'background:#0f7b45' : 'background:#f3f4f6',
                    active ? 'color:#fff' : 'color:#6b7280',
                    active ? 'border:1px solid #0f7b45' : 'border:1px solid #d9dde3',
                ].join(';');
            };
            const showState = () => paint('Token attivo: le chiamate partono autenticate', true);
            paint('Nessun token: fai il login', false);

            const attach = () => { document.body.appendChild(badge); document.body.appendChild(panel); };
            if (document.body) { attach(); } else { document.addEventListener('DOMContentLoaded', attach); }
        })();
    </script>

    <style>
        html, body { margin:0; height:100%; }
        body { background-color: var(--color-canvas); }
        /* issues about the dark theme of stoplight/mosaic-code-viewer using web component:
         * https://github.com/stoplightio/elements/issues/2188#issuecomment-1485461965
         */
        [data-theme="dark"] .token.property {
            color: rgb(128, 203, 196) !important;
        }
        [data-theme="dark"] .token.operator {
            color: rgb(255, 123, 114) !important;
        }
        [data-theme="dark"] .token.number {
            color: rgb(247, 140, 108) !important;
        }
        [data-theme="dark"] .token.string {
            color: rgb(165, 214, 255) !important;
        }
        [data-theme="dark"] .token.boolean {
            color: rgb(121, 192, 255) !important;
        }
        [data-theme="dark"] .token.punctuation {
            color: #dbdbdb !important;
        }
    </style>
</head>
<body style="height: 100vh; overflow-y: hidden">
<elements-api
    id="docs"
    @foreach($config->renderer()->all(except: ['theme']) as $key => $value)
        @continue(! $value)
        {{ $key }}="{{ $value === true ? 'true' : ($value === false ? 'false' : $value) }}"
    @endforeach
/>
<script>
    (async () => {
        const docs = document.getElementById('docs');
        docs.apiDescriptionDocument = @json($spec);
    })();
</script>

@if($config->renderer()->get('theme', 'light') === 'system')
    <script>
        var mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

        function updateTheme(e) {
            if (e.matches) {
                window.document.documentElement.setAttribute('data-theme', 'dark');
                window.document.getElementsByName('color-scheme')[0].setAttribute('content', 'dark');
            } else {
                window.document.documentElement.setAttribute('data-theme', 'light');
                window.document.getElementsByName('color-scheme')[0].setAttribute('content', 'light');
            }
        }

        mediaQuery.addEventListener('change', updateTheme);
        updateTheme(mediaQuery);
    </script>
@endif
</body>
</html>
