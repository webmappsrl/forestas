{{--
  Apertura comune delle schede di Forestas (oc:8982): classe, stili del package, colore primario e
  preambolo con la grafica di SARDEGNA Sentieri. Usa le variabili del contratto della view
  ($styleFiles, $primaryColor) e riceve: $sectionColor (token `tipo-*`), $copyright (già escaped).
--}}
\documentclass[10pt,a4paper]{article}
@foreach ($styleFiles as $styleName)
\usepackage{{!! $styleName !!}}
@endforeach
\definecolor{wmprimary}{HTML}{{!! strtoupper($primaryColor) !!}}
@include('wm-package::print.partials.preamble', ['sectionColor' => $sectionColor, 'copyright' => $copyright])
