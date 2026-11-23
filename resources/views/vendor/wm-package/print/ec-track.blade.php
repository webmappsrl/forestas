{{--
  Scheda PDF del percorso con la grafica di SARDEGNA Sentieri (oc:8982, R16-R18): override della
  view `wm-package::print.ec-track`. Il contratto delle variabili è nel docblock della view del
  package (wm-package/resources/views/print/ec-track.blade.php): qui si usano quelle, più $forestas,
  calcolato da `ForestasTrackDetail::build()` come nel dettaglio pubblico, e $sheet, gli stessi dati
  già escaped per LaTeX (`App\Support\ForestasPrintSheet`).

  Modello: la pagina di dettaglio del sito WordPress (tema forestas-child, wp-package/track.php),
  condensata in un foglio A4 da stampare fronte e retro (oc:8982, R17 e R20, call del 23/11):
  - pagina 1: testata (logotipo, etichetta di tipo con il codice, area, badge di stato, nome,
    località, filetto oro); l'avviso di percorribilità se pubblicato; il blocco di stato (non per gli
    itinerari, come sul sito): fascia «Percorribilità», poi su due righe Validazione catastale e
    Percorribilità affiancate, Accessibilità a tutta larghezza con la scala a sinistra e le tre icone
    a destra; «Sentiero per tutti» se c'è; «Il sentiero in breve» (Lunghezza, Dislivello totale,
    Tempo di percorrenza, Quota min — max, «—» se mancano); pochi dati catastali e altimetrici, solo
    quelli che non sono già sopra; il rimando alla pagina del sito (QR code e indirizzo);
  - pagina 2: la mappa da sola, A4 al vivo, senza testata né piè di pagina, con il credito
    nell'angolo ($mapWidthMm x $mapHeightMm, features.print.map.*, 210 x 297 mm).
  Niente descrizione, roadbook, attività e avvertenze né galleria: è la versione breve, da volantino.
  Le parti senza dati non compaiono.

  Attenzione: in LaTeX non scrivere "{{" letterale (Blade): usa uno spazio o @{{.
--}}
@php
    $track = \App\Support\ForestasPrintSheet::trackModel($model);
    $forestas = \App\Support\ForestasTrackDetail::build($track);
    $sheet = \App\Support\ForestasPrintSheet::track($track, $forestas, $locale);
    $l = $sheet['labels'];
    // Variabili del contratto lette senza `??`: se il package le rinomina la view si rompe subito
    // (e il test che passa da PrintService::generate() con lei).
    $credit = $labels['© OpenStreetMap contributors, Webmapp'];
    $validation = $sheet['validation'];
    $walkability = $sheet['walkability'];
    $accessibility = $sheet['accessibility'];
    $name = trim((string) $name) === '' ? '' : $sheet['name'];
    // Sigle e tipologie della colonna Accessibilità, composte qui: una direttiva Blade attaccata a
    // un comando LaTeX (`\noindent@foreach`) non si compilerebbe.
    $grades = implode('', array_map(fn ($grade) => '\\ForestasSigla{'.$grade.'}', $accessibility['grades']));
    $disabilities = implode('\\hspace{1.5mm}', array_map(
        fn ($item) => '\\ForestasDisabilita{'.($item['on'] ? 1 : 0).'}{'.$item['icon'].'}{'.$item['label'].'}',
        $accessibility['disabilities'],
    ));
@endphp
\documentclass[10pt,a4paper]{article}
@foreach ($styleFiles as $styleName)
\usepackage{{!! $styleName !!}}
@endforeach
\definecolor{wmprimary}{HTML}{{!! strtoupper($primaryColor) !!}}
@include('wm-package::print.partials.preamble', [
    'sectionColor' => $sheet['type']['color'],
    'copyright' => $l['Footer copyright'],
])
\begin{document}
{{-- Pagina 1: tutto in un box che, se non ci sta, si riduce in scala (ForestasPaginaUnica). --}}
\begin{ForestasPaginaUnica}
{{-- 1. Testata. --}}
\noindent\ForestasLogotipo{16pt}\hfill\ForestasOcchiello{{!! $sheet['sheetLabel'] !!}}
\ForestasFiletto
\vspace{8pt}
\noindent\ForestasEtichettaTipo{{!! $sheet['type']['text'] !!}}@if ($sheet['ref'])\hspace{6pt}\ForestasRiferimento{{!! $sheet['ref'] !!}}@endif
@if ($sheet['area'])\hspace{6pt}\ForestasOcchiello{{!! $sheet['area'] !!}}@endif
@if ($sheet['kind'] !== 'itinerario')\hfill\ForestasBadgeStato{{!! $validation['color'] !!}}{{!! $validation['badge'] !!}}@endif

\vspace{2pt}
\TitoloScheda{{!! $name !!}}
@if ($sheet['location'])
\begingroup\color{ink-2}\ForestasStileSottotitolo {!! $sheet['location'] !!}\par\endgroup
@endif
\FilettoOro
@if ($sheet['notice'])
{{-- 2. Avviso di percorribilità: è un dato di stato, nel colore dello stato, e apre il blocco di stato. --}}
\ForestasRiquadro{{!! $sheet['noticeColor'] !!}}{\begingroup\color{ink}\bfseries {!! $l['Notice'] !!}\endgroup{}@if ($sheet['notice']['date'])\hspace{6pt}\begingroup\color{ink-3}\ForestasStileDidascalia {!! $sheet['notice']['date'] !!}\endgroup{}@endif\par
\begingroup\color{ink-2}{!! $sheet['notice']['message'] !!}\endgroup}
@endif
@if ($sheet['kind'] !== 'itinerario')
{{-- 3. Blocco di stato (opzione A): fascia, poi due righe. La cella dell'accessibilità ha larghezza
     esplicita: una colonna X in un \multicolumn non prende la larghezza delle due colonne. --}}
\ForestasFasciaPercorribilita{{!! $walkability['color'] !!}}{{!! $walkability['band']['title'] !!}}{{!! (string) $walkability['band']['body'] !!}}
\par\vspace{6pt}
\noindent\begin{tabularx}{\linewidth}{|>{\raggedright\arraybackslash}X|>{\raggedright\arraybackslash}X|}
\hline
\rule{0pt}{14pt}\ForestasIndicatore{{!! $l['Trail register validation'] !!}}{\ForestasScala{{!! $validation['color'] !!}}{{!! $validation['level'] !!}}{{!! $validation['total'] !!}}}{\ForestasValore{{!! $validation['value'] !!}}}{} &
\rule{0pt}{14pt}\ForestasIndicatore{{!! $l['Walkability'] !!}}{\ForestasScala{{!! $walkability['color'] !!}}{{!! $walkability['level'] !!}}{{!! $walkability['total'] !!}}}{\ForestasValore{{!! $walkability['value'] !!}}}{} \\
\hline
\multicolumn{2}{|p{\dimexpr\linewidth-2\arrayrulewidth-2\tabcolsep\relax}|}{\rule{0pt}{14pt}\begin{minipage}[t]{0.5\linewidth}\setlength{\parskip}{0pt}\ForestasIndicatore{{!! $l['Accessibility'] !!}}{\ForestasScala{{!! $accessibility['color'] !!}}{{!! $accessibility['level'] !!}}{{!! $accessibility['total'] !!}}}{\noindent {!! $grades !!}\par\ForestasValore{{!! $accessibility['value'] !!}}}{}\par\nointerlineskip\rule[-1mm]{0pt}{1mm}\end{minipage}\hfill\begin{minipage}[t]{0.46\linewidth}\raggedleft\vspace{0pt}{!! $disabilities !!}\end{minipage}} \\
\hline
\end{tabularx}
@if ($sheet['inclusivity'])
\par\vspace{6pt}
\ForestasRiquadro{sar-blu}{\ForestasOcchiello{{!! $l['Trail for everyone'] !!}}\par
\begingroup\color{ink}\bfseries {!! $sheet['inclusivity']['class'] !!}\endgroup\hspace{6pt}\begingroup\color{ink-2}{!! $l['Inclusivity score'] !!}: {!! $sheet['inclusivity']['score'] !!}\endgroup\par
{{-- I cinque dati in una griglia di tre colonne, come i dati catastali: la tabella non starebbe nella pagina 1. --}}
\vspace{2pt}\begin{ForestasGriglia}{3}
@foreach ($sheet['inclusivity']['rows'] as $row)
\ForestasDato{{!! $row['label'] !!}}{{!! $row['value'] !!}}@if ($loop->last) \\ @elseif ($loop->iteration % 3 === 0) \\[4pt]
@else & @endif
@endforeach
\end{ForestasGriglia}}
@endif
@endif
{{-- 4. In breve. --}}

\TitoloSezione{{!! $sheet['atAGlance'] !!}}
\noindent\begin{tabularx}{\linewidth}{@{}*{4}{>{\raggedright\arraybackslash}X}@{}}
@foreach ($sheet['facts'] as $fact)
\ForestasFatto{{!! $fact['value'] !!}}{{!! $fact['label'] !!}}@if (! $loop->last) & @else \\ @endif
@endforeach
\end{tabularx}
\ForestasFiletto
@if ($sheet['keyData'] !== [] || $sheet['link'])
{{-- 5. Dati catastali e altimetrici, solo quelli che non sono già sopra, in una griglia di tre colonne;
     sotto i dati l'indirizzo della pagina del sito, a destra il suo QR code. --}}

\par\vspace{2pt}\ForestasOcchiello{{!! $sheet['dataTitle'] !!}}
\par\nointerlineskip\vspace{4pt}\noindent\begin{minipage}[t]{\dimexpr\linewidth-@if ($sheet['link'])28mm @else 0mm @endif\relax}\vspace{0pt}
@if ($sheet['keyData'] !== [])
\begin{ForestasGriglia}{3}
@foreach ($sheet['keyData'] as $row)
\ForestasDato{{!! $row['label'] !!}}{{!! $row['value'] !!}}@if ($loop->last) \\ @elseif ($loop->iteration % 3 === 0) \\[6pt]
@else & @endif
@endforeach
\end{ForestasGriglia}
@endif
@if ($sheet['link'])
\ForestasIndirizzo{{!! $l['See the page on the website'] !!}}{{!! $sheet['link']['text'] !!}}
@endif
\end{minipage}@if ($sheet['link'])\hfill\begin{minipage}[t]{22mm}\vspace{0pt}\ForestasQr{{!! $sheet['link']['qr'] !!}}\end{minipage}@endif
\par
@endif
\end{ForestasPaginaUnica}
@if ($mapFile)
{{-- 7. Pagina 2: la mappa da sola, A4 al vivo. --}}
\ForestasMappaPagina{{!! $mapFile !!}}{{!! $credit !!}}
@endif
\end{document}
