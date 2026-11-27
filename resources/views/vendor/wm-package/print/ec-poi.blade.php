{{--
  Scheda PDF del punto di interesse con la grafica di SARDEGNA Sentieri (oc:8982, R19): override
  della view `wm-package::print.ec-poi`, con lo stesso linguaggio grafico della scheda del percorso.
  Il contratto delle variabili è nel docblock della view del package
  (wm-package/resources/views/print/ec-poi.blade.php; $relatedPois non è passato ai POI): qui si
  usano quelle, più $forestas, calcolato da `ForestasPoiDetail::build()` come nel dettaglio
  pubblico, e $sheet, gli stessi dati già escaped per LaTeX (`App\Support\ForestasPrintSheet`).

  Modello: la pagina del POI del sito WordPress (tema forestas-child, wp-package/poi.php), condensata
  come la scheda del percorso in un foglio A4 di due pagine (oc:8982, R21): pagina 1 con la testata
  (logotipo, etichetta di tipo, area, nome, località, filetto oro), «Il punto in breve» (tipologia,
  comune, area geografica, codice, enti) con a destra il QR code della pagina del sito e sotto
  l'indirizzo, poi al massimo sei foto; pagina 2 con la sola mappa, A4 al vivo. Niente descrizione né
  «come arrivare». Non ci sono i percorsi vicini né i punti di interesse correlati. Le parti senza dati
  non compaiono.

  La rotta del package risolve `ec-poi` con il modello del package: `poiModel()` carica quello di
  Forestas, che ha le relazioni (enti, POI correlati, percorsi vicini).

  Attenzione: in LaTeX non scrivere "{{" letterale (Blade): usa uno spazio o @{{.
--}}
@php
    $poi = \App\Support\ForestasPrintSheet::poiModel($model);
    $forestas = \App\Support\ForestasPoiDetail::build($poi);
    $sheet = \App\Support\ForestasPrintSheet::poi($poi, $forestas, $locale);
    $l = $sheet['labels'];
    // Due righe da tre: con la testata e «Il punto in breve» la pagina 1 basta (oc:8982, R21).
    $photos = array_slice($photos, 0, \App\Support\ForestasPrintSheet::POI_PHOTOS);
@endphp
@include('wm-package::print.partials.head', ['sectionColor' => $sheet['type']['color'], 'copyright' => $l['Footer copyright']])
\begin{document}
{{-- Pagina 1: tutto in un box che, se non ci sta, si riduce in scala (ForestasPaginaUnica). --}}
\begin{ForestasPaginaUnica}
{{-- Testata. --}}
@include('wm-package::print.partials.header', [
    'sheetLabel' => $l['Point of interest sheet'],
    'typeText' => $sheet['type']['text'],
    'ref' => null,
    'area' => $sheet['area'],
    'badge' => null,
    'title' => $name,
    'location' => $sheet['location'],
])
{{-- «Il punto in breve», con a destra il QR code della pagina del sito e sotto l'indirizzo. --}}

\TitoloSezione{{!! $l['At a glance'] !!}}
\begin{ForestasConRimando}{{!! $sheet['link']['qr'] ?? '' !!}}
\begin{Tabella}
@foreach ($sheet['rows'] as $row)
\RigaTabella{{!! $row['label'] !!}}{{!! $row['value'] !!}}
@endforeach
@foreach (is_array($stats) ? $stats : [] as $stat)
\RigaTabella{{!! $stat['label'] !!}}{{!! $stat['value'] !!}}
@endforeach
\end{Tabella}
@if ($sheet['link'])
\ForestasIndirizzo{{!! $l['See the page on the website'] !!}}{{!! $sheet['link']['text'] !!}}
@endif
\end{ForestasConRimando}

\par
@if ($photos !== [])
{{-- Foto: al massimo sei, tre per riga. --}}

\TitoloSezione{{!! $l['Photogallery'] !!}}
\noindent
@foreach ($photos as $photo)
\makebox[0.32\linewidth][c]{\includegraphics[width=0.32\linewidth,height=45mm,keepaspectratio]{{!! $photo !!}}}@if ($loop->iteration % 3 === 0 && ! $loop->last)\par\vspace{6pt}\noindent @elseif (! $loop->last)\hspace{0.02\linewidth}@endif%
@endforeach
\par
@endif
\end{ForestasPaginaUnica}
@if ($mapFile)
{{-- Pagina 2: la mappa da sola, A4 al vivo, come nella scheda del percorso. --}}
\ForestasMappaPagina{{!! $mapFile !!}}
@endif
\end{document}
