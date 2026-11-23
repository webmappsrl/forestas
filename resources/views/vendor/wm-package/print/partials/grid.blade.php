{{--
  Dati brevi in una griglia di tre colonne (dati catastali, punteggi di inclusività), oc:8982.
  Riceve: $rows, lista di ['label' => ..., 'value' => ...] già escaped.
--}}
\begin{ForestasGriglia}{3}
@foreach ($rows as $row)
\ForestasDato{{!! $row['label'] !!}}{{!! $row['value'] !!}}@if ($loop->last) \\ @elseif ($loop->iteration % 3 === 0) \\[5pt]
@else & @endif
@endforeach
\end{ForestasGriglia}
