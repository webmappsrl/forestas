{{--
  Scheda PDF del punto di interesse con la grafica di SARDEGNA Sentieri (oc:8982, R19): override
  della view `wm-package::print.ec-poi`, con lo stesso linguaggio grafico della scheda del percorso.
  Il contratto delle variabili è nel docblock della view del package
  (wm-package/resources/views/print/ec-poi.blade.php; $relatedPois non è passato ai POI): qui si
  usano quelle, più $forestas, calcolato da `ForestasPoiDetail::build()` come nel dettaglio
  pubblico, e $sheet, gli stessi dati già escaped per LaTeX (`App\Support\ForestasPrintSheet`).

  Modello: la pagina del POI del sito WordPress (tema forestas-child, wp-package/poi.php), su pagine
  A4: la testata (logotipo, etichetta di tipo, area, nome, località, filetto oro), «Il punto in breve»
  (tipologia, comune, area geografica, codice, enti), «Il punto di interesse» (descrizione, come
  arrivare), la galleria e il rimando alla pagina del sito (QR code e indirizzo); in fondo la mappa,
  da sola in una pagina A4 al vivo, come nella scheda del percorso. Non ci sono i percorsi vicini né i
  punti di interesse correlati. Le parti senza dati non compaiono.

  La rotta del package risolve `ec-poi` con il modello del package: `poiModel()` carica quello di
  Forestas, che ha le relazioni (enti, POI correlati, percorsi vicini).

  Attenzione: in LaTeX non scrivere "{{" letterale (Blade): usa uno spazio o @{{.
--}}
@php
    $poi = \App\Support\ForestasPrintSheet::poiModel($model);
    $forestas = \App\Support\ForestasPoiDetail::build($poi);
    $sheet = \App\Support\ForestasPrintSheet::poi($poi, $forestas, $locale);
    $l = $sheet['labels'];
    // Variabili del contratto lette senza `??`: se il package le rinomina la view si rompe subito.
    $credit = $labels['© OpenStreetMap contributors, Webmapp'];
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
{{-- Testata. --}}
\noindent\ForestasLogotipo{16pt}\hfill\ForestasOcchiello{{!! $l['Point of interest sheet'] !!}}
\ForestasFiletto
\vspace{8pt}
\noindent\ForestasEtichettaTipo{{!! $sheet['type']['text'] !!}}@if ($sheet['area'])\hspace{6pt}\ForestasOcchiello{{!! $sheet['area'] !!}}@endif


\vspace{2pt}
\TitoloScheda{{!! $name !!}}
@if ($sheet['location'])
\begingroup\color{ink-2}\ForestasStileSottotitolo {!! $sheet['location'] !!}\par\endgroup
@endif
\FilettoOro

\TitoloSezione{{!! $l['At a glance'] !!}}
\begin{Tabella}
@foreach ($sheet['rows'] as $row)
\RigaTabella{{!! $row['label'] !!}}{{!! $row['value'] !!}}
@endforeach
@foreach (is_array($stats) ? $stats : [] as $stat)
\RigaTabella{{!! $stat['label'] !!}}{{!! $stat['value'] !!}}
@endforeach
\end{Tabella}
@if (trim((string) $descriptionLatex) !== '' || $sheet['directions'])

\TitoloSezione{{!! $l['The point of interest'] !!}}
@if (trim((string) $descriptionLatex) !== '')
\ForestasSottotitolo{{!! $labels['Description'] !!}}
{!! $descriptionLatex !!}
@endif
@if ($sheet['directions'])

\ForestasSottotitolo{{!! $l['Directions'] !!}}
{!! $sheet['directions'] !!}
@endif
@endif
@if ($photos !== [])

\TitoloSezione{{!! $l['Photogallery'] !!}}
\noindent
@foreach ($photos as $photo)
\makebox[0.32\linewidth][c]{\includegraphics[width=0.32\linewidth,height=45mm,keepaspectratio]{{!! $photo !!}}}@if ($loop->iteration % 3 === 0 && ! $loop->last)\par\vspace{6pt}\noindent @elseif (! $loop->last)\hspace{0.02\linewidth}@endif%
@endforeach
\par
@endif
@if ($sheet['link'])
{{-- Rimando alla pagina del sito: QR code e indirizzo. --}}
\par\vspace{12pt}\noindent\ForestasQr{{!! $sheet['link']['qr'] !!}}
\ForestasIndirizzo{{!! $l['See the page on the website'] !!}}{{!! $sheet['link']['text'] !!}}
@endif
@if ($mapFile)
{{-- La mappa da sola, A4 al vivo, nell'ultima pagina (come la scheda del percorso). --}}
\ForestasMappaPagina{{!! $mapFile !!}}{{!! $credit !!}}
@endif
\end{document}
