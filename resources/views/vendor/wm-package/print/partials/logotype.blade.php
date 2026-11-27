{{--
  Logotipo «SARDEGNA Sentieri» composto in testo, mai come immagine (oc:8982, R7).
  Regole dal design system (README §3, components/brand/LogotipoSardegna.jsx):
  - «SARDEGNA» in Work Sans 700, una tinta per lettera in ordine fisso
    S terra · A verde · R blu · D terra · E oro · G blu · N terra · A oro, tracking −0.01em;
  - coda «Sentieri» in ink-3, Work Sans 300, corpo 0,62 del logotipo, margine sinistro 0,22em;
  - sotto il corpo 18 (px a schermo, circa 13,5 pt) la coda si elimina: \ForestasLogotipoSolo.
  I pesi si richiamano per nome: con i TTF statici `\bfseries` su «Work Sans» dà il SemiBold (600).
  LetterSpacing di fontspec è in centesimi di em: -1 = −0.01em.
  Uso: \ForestasLogotipo{16pt}, \ForestasLogotipoSolo{9pt}. Incluso da partials/preamble.
--}}
\newfontfamily\ForestasLogoBold{Work Sans Bold}[LetterSpacing=-1]
\newfontfamily\ForestasLogoLight{Work Sans Light}[LetterSpacing=-1]
\newcommand{\ForestasLogoParola}[1]{%
\mbox{\ForestasLogoBold\fontsize{#1}{#1}\selectfont\textcolor{sar-terra}{S}\textcolor{sar-verde}{A}\textcolor{sar-blu}{R}\textcolor{sar-terra}{D}\textcolor{sar-oro}{E}\textcolor{sar-blu}{G}\textcolor{sar-terra}{N}\textcolor{sar-oro}{A}}}
\newcommand{\ForestasLogotipo}[1]{\mbox{\ForestasLogoParola{#1}\hspace{\dimexpr #1*22/100\relax}{\ForestasLogoLight\fontsize{\dimexpr #1*62/100\relax}{#1}\selectfont\color{ink-3}Sentieri}}}
\newcommand{\ForestasLogotipoSolo}[1]{\ForestasLogoParola{#1}}
