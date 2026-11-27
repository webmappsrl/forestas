{{--
  Testata comune delle schede di Forestas (oc:8982): logotipo e occhiello, filetto, etichetta di tipo
  con il riferimento e l'area, badge di stato a destra, nome, località, filetto oro. Tutti i testi
  arrivano già escaped. Riceve: $sheetLabel, $typeText, $ref (o null), $area (o null),
  $badge (['color' => token, 'text' => testo] o null), $title, $location (o null).
--}}
\noindent\ForestasLogotipo{16pt}\hfill\ForestasOcchiello{{!! $sheetLabel !!}}
\ForestasFiletto
\vspace{8pt}
\noindent\ForestasEtichettaTipo{{!! $typeText !!}}@if ($ref)\hspace{6pt}\ForestasRiferimento{{!! $ref !!}}@endif
@if ($area)\hspace{6pt}\ForestasOcchiello{{!! $area !!}}@endif
@if ($badge)\hfill\ForestasBadgeStato{{!! $badge['color'] !!}}{{!! $badge['text'] !!}}@endif

\vspace{2pt}
\TitoloScheda{{!! $title !!}}
@if ($location)
\begingroup\color{ink-2}\ForestasStileSottotitolo {!! $location !!}\par\endgroup
@endif
\FilettoOro
