{{--
  Preambolo comune alle schede di Forestas (oc:8982, R16): colori, font, stili di testo e macro
  della grafica SARDEGNA Sentieri, generati dal design system (artifact «SARDEGNA Sentieri Design
  System», tokens.json, README §3-§5, componenti LogotipoSardegna, BadgeStato, FilettoOro,
  PieDiPagina, template scheda-sentiero). Non ci sono token da rigenerare: se il design system cambia,
  si aggiorna questo file.

  Si include dopo \usepackage{wm-print} e \definecolor{wmprimary}: le macro di wm-print usate qui
  (\TitoloScheda, \TitoloSezione, \FilettoOro, \PillDifficolta, Tabella, \RigaTabella) si
  ridefiniscono con \renewcommand; le nuove hanno il prefisso Forestas. wmprimary non si usa.

  Riceve: $sectionColor  token `tipo-*` del filetto di sezione e dell'etichetta di tipo;
          $copyright     riga del copyright RAS per il piè di pagina, già escaped.

  Regole del design system applicate:
  - fondo pagina bianco (surf); surf-2 solo per riquadri e fasce, mai per la pagina;
  - sar-terra e sar-oro solo come segno grafico (logotipo, filetti), mai testo;
  - colori st-* solo per lo stato (scale, badge, filetto laterale), sempre con numero ed etichetta;
  - tipo-* solo per l'etichetta di tipo e il filetto di sezione;
  - Work Sans unica famiglia, pesi reali 300/400/500/600/700, nessun corsivo (riservato al sardo);
  - scala tipografica dei token convertita da px a pt (x 0,75, 96 dpi): titolo pagina 23,25 pt,
    titolo sezione 16,5, sottotitolo 12,75, corpo 12 (il minimo dei documenti), didascalia 9,75,
    occhiello 8,625 maiuscolo spaziato; raggi 2 px = 1,5 pt (controlli) e 3 px = 2,25 pt (cornici);
    filetto 3 px = 2,25 pt; bordo 1 px = 0,75 pt, bordo attivo 1,5 px = 1,125 pt.
  In LaTeX non scrivere "{{" letterale (Blade): i gruppi si aprono con \begingroup.
--}}
@include('wm-package::print.partials.logotype')
\RequirePackage{colortbl}
\RequirePackage{tikz}
\usetikzlibrary{svg.path}
\RequirePackage{eso-pic}
\RequirePackage{qrcode}

% Colori del design system usati dalle schede: valori esatti di tokens.json (tema chiaro). Gli altri
% token (sar-terra-testo, sar-oro-testo, surf-3, tipo-da-sapere, tipo-news) si aggiungono quando servono.
\definecolor{sar-blu}{HTML}{254877}
\definecolor{sar-verde}{HTML}{566D27}
\definecolor{sar-terra}{HTML}{CA5C30}
\definecolor{sar-oro}{HTML}{E9A33F}
\definecolor{st-4}{HTML}{4B6B22}
\definecolor{st-3}{HTML}{8A5A00}
\definecolor{st-2}{HTML}{7A6A55}
\definecolor{st-1}{HTML}{9B1B1B}
\definecolor{tipo-sentiero}{HTML}{DE1B0D}
\definecolor{tipo-itinerario}{HTML}{58810B}
\definecolor{tipo-cammino}{HTML}{1A416F}
\definecolor{tipo-da-vedere}{HTML}{B7117F}
\definecolor{ink}{HTML}{1A1A1A}
\definecolor{ink-2}{HTML}{4A4A4A}
\definecolor{ink-3}{HTML}{767676}
\definecolor{line}{HTML}{DEDAD2}
\definecolor{line-2}{HTML}{EEEBE5}
\definecolor{surf}{HTML}{FFFFFF}
\definecolor{surf-2}{HTML}{F7F5F1}
\colorlet{forestas-tipo}{{!! $sectionColor !!}}
% Ruoli di wm-print sui colori del design system (testo ink, didascalie ink-3, linee line, filetti oro).
\colorlet{wmtitle}{ink}\colorlet{wmaccent}{ink}\colorlet{wmrule}{sar-oro}
\colorlet{wmtext}{ink}\colorlet{wmmuted}{ink-3}\colorlet{wmline}{line}
\pagecolor{surf}

% Work Sans, famiglia unica, cifre tabellari: con i TTF statici dell'immagine \bfseries è il SemiBold 600
% (titoli); Medium 500, Light 300 e Bold 700 (logotipo) si chiamano per nome.
% Senza ripiego: se l'immagine non ha Work Sans la compilazione fallisce («font cannot be found»)
% invece di uscire in silenzio in Latin Modern, come già fanno Medium, Light e Bold chiamati per nome.
\setmainfont{Work Sans}[Numbers=Monospaced]
\setsansfont{Work Sans}[Numbers=Monospaced]
\newfontfamily\ForestasMedium{Work Sans Medium}[Numbers=Monospaced]
\renewcommand{\familydefault}{\rmdefault}

% Stili di testo della scala tipografica dei token.
\newcommand{\ForestasStileTitoloPagina}{\fontsize{23.25pt}{26.74pt}\selectfont\bfseries\addfontfeature{LetterSpacing=-2.5}}
\newcommand{\ForestasStileTitoloSezione}{\fontsize{16.5pt}{20.63pt}\selectfont\bfseries\addfontfeature{LetterSpacing=-1.5}}
\newcommand{\ForestasStileSottotitolo}{\ForestasMedium\fontsize{12.75pt}{17.21pt}\selectfont}
\newcommand{\ForestasStileCorpo}{\fontsize{12pt}{19.2pt}\selectfont\mdseries}
\newcommand{\ForestasStileDidascalia}{\fontsize{9.75pt}{14.14pt}\selectfont\mdseries}
\newcommand{\ForestasStileOcchiello}{\fontsize{8.625pt}{11pt}\selectfont\bfseries\addfontfeature{LetterSpacing=6}}

% Corpo 12 pt, interlinea 1,6, testo a bandiera a sinistra, niente rientri.
\AtBeginDocument{\color{ink}\ForestasStileCorpo\raggedright\setlength{\parindent}{0pt}\setlength{\parskip}{6pt plus 2pt}}
\urlstyle{same}
\setlength{\arrayrulewidth}{0.75pt}
\arrayrulecolor{line-2}

% Salto di pagina se non resta lo spazio dato: un titolo non resta solo in fondo alla pagina.
\newcommand{\ForestasSpazio}[1]{\par\vskip 0pt plus #1\relax\penalty-200\vskip 0pt plus -#1\relax}

% Occhiello: maiuscolo spaziato in ink-3 (etichette di dati e di stato).
\newcommand{\ForestasOcchiello}[1]{\begingroup\color{ink-3}\ForestasStileOcchiello\MakeUppercase{#1}\endgroup}

% Filetto oro (FilettoOro): il segno firma del design system, 3 px = 2,25 pt.
\renewcommand{\FilettoOro}{\par\vspace{4pt}\noindent\begingroup\color{sar-oro}\rule{\linewidth}{2.25pt}\endgroup\par\vspace{2pt}}

% Filetto chiaro (bordo 1 px in line).
\newcommand{\ForestasFiletto}{\par\noindent\begingroup\color{line}\rule{\linewidth}{0.75pt}\endgroup\par}

% Titolo della scheda: titolo pagina dei token, in ink.
\renewcommand{\TitoloScheda}[1]{\par\begingroup\color{ink}\ForestasStileTitoloPagina #1\par\endgroup}

% Titolo di sezione: filetto di sezione laterale (3 px) nel colore del tipo, titolo in ink su bianco.
\newsavebox{\ForestasBox}
\renewcommand{\TitoloSezione}[1]{%
\ForestasSpazio{45mm}\vspace{10pt}%
\sbox{\ForestasBox}{\begin{minipage}[t]{\dimexpr\linewidth-11.25pt\relax}\vspace{0pt}\vspace{3pt}\raggedright\color{ink}\ForestasStileTitoloSezione #1\par\vspace{4pt}\end{minipage}}%
\noindent\begingroup\color{forestas-tipo}\rule[-\dp\ForestasBox]{2.25pt}{\dimexpr\ht\ForestasBox+\dp\ForestasBox\relax}\endgroup\hspace{9pt}\usebox{\ForestasBox}\par\nopagebreak\vspace{2pt}\nopagebreak}

% Etichetta di tipo: testo bianco su tinta piena del tipo (tipo-* ammette il testo bianco), raggio 2 px.
\newcommand{\ForestasEtichettaTipo}[1]{\tikz[baseline=(t.base)]\node[fill=forestas-tipo,rounded corners=1.5pt,inner xsep=4pt,inner ysep=2.5pt,text=white](t){\ForestasStileOcchiello\MakeUppercase{#1}};}

% Badge di stato (BadgeStato): testo bianco su colore di stato, numero ed etichetta, raggio 2 px.
\newcommand{\ForestasBadgeStato}[2]{\tikz[baseline=(t.base)]\node[fill=#1,rounded corners=1.5pt,inner xsep=6pt,inner ysep=2.5pt,text=white](t){\fontsize{8.625pt}{11pt}\selectfont\bfseries #2};}

% Codice del percorso accanto all'etichetta di tipo (codice di catasto, maiuscolo, ink-2).
\newcommand{\ForestasRiferimento}[1]{\begingroup\color{ink-2}\ForestasStileOcchiello #1\endgroup}

% Pillola di difficoltà (PillDifficolta): contorno e testo neutri, raggio 2 px; i livelli
% praticabile/esperti del componente non corrispondono ai gradi T, E, EE.
\renewcommand{\PillDifficolta}[1]{\tikz[baseline=(t.base)]\node[draw=line,line width=1.125pt,rounded corners=1.5pt,inner xsep=5pt,inner ysep=2pt,text=ink](t){\ForestasStileDidascalia\bfseries #1};}

% Riquadro con filetto laterale (avvisi): #1 colore del filetto (quello dello stato), #2 contenuto.
\newcommand{\ForestasRiquadro}[2]{%
\par\sbox{\ForestasBox}{\begin{minipage}[t]{\dimexpr\linewidth-12pt\relax}\vspace{0pt}\raggedright\setlength{\parskip}{3pt}#2\end{minipage}}%
\noindent\begingroup\color{#1}\rule[-\dp\ForestasBox]{2.25pt}{\dimexpr\ht\ForestasBox+\dp\ForestasBox\relax}\endgroup\hspace{9.75pt}\usebox{\ForestasBox}\par}

% Fascia della percorribilità: riquadro sabbia (surf-2) con filetto laterale 3 px nel colore dello
% stato (ink-3 senza stato). #1 colore, #2 titolo, #3 testo (vuoto: nessuno). Apre il blocco di stato.
\newcommand{\ForestasFasciaPercorribilita}[3]{%
\par\sbox{\ForestasBox}{\begin{minipage}[t]{\dimexpr\linewidth-2.25pt-24pt\relax}\vspace{9pt}\raggedright
\begingroup\color{ink}\bfseries #2\par\endgroup
\if\relax\detokenize{#3}\relax\else\vspace{2pt}\begingroup\color{ink-2}\ForestasStileDidascalia #3\par\endgroup\fi
\vspace{9pt}\end{minipage}}%
\noindent\begingroup\color{#1}\rule[-\dp\ForestasBox]{2.25pt}{\dimexpr\ht\ForestasBox+\dp\ForestasBox\relax}\endgroup%
\begingroup\setlength{\fboxsep}{0pt}\colorbox{surf-2}{\hspace{12pt}\usebox{\ForestasBox}\hspace{12pt}}\endgroup\par}

% Scala a segmenti degli indicatori: #1 colore dei segmenti accesi, #2 livello, #3 totale.
% Segmenti 8 x 2,6 mm, raggio 2 px; spenti con il bordo 1 px in line, accesi pieni.
\newcommand{\ForestasScala}[3]{\begin{tikzpicture}[baseline=0pt]
\foreach \i in {1,...,#3}{\pgfmathsetmacro{\x}{(\i-1)*9}%
\ifnum\i>#2\relax\draw[line,line width=0.75pt,rounded corners=1.5pt,fill=white](\x mm,0)rectangle++(8mm,2.6mm);%
\else\draw[#1,line width=0.75pt,rounded corners=1.5pt,fill=#1](\x mm,0)rectangle++(8mm,2.6mm);\fi}
\end{tikzpicture}}

% Sigle sotto i segmenti (AT, AE, AEE), centrate sui segmenti da 8 mm.
\newcommand{\ForestasSigla}[1]{\makebox[9mm][l]{\makebox[8mm][c]{\begingroup\color{ink-2}\ForestasStileDidascalia #1\endgroup}}}

% Indicatore: occhiello, scala (#2), valore (#3) e altro (#4: sigle e icone), in una colonna.
\newcommand{\ForestasIndicatore}[4]{\ForestasOcchiello{#1}\par\vspace{5pt}#2\par#3#4}
\newcommand{\ForestasValore}[1]{\vspace{3pt}\begingroup\color{ink}\bfseries #1\par\endgroup}

% Tipologia di disabilità, affiancata alle altre come sul sito: un riquadro 15 x 15 mm con in testa
% una fascia piena con l'etichetta in bianco (su una o due righe) e sotto, in un riquadro tratteggiato,
% l'icona a tratto del tema. Spenta (non certificata): fascia in ink-2, icona in ink-2, bordo
% tratteggiato in ink-3, come sul sito. Accesa (certificata): fascia e icona in sar-blu (elemento
% attivo) e bordo pieno.
% #1 1/0, #2 comandi TikZ del disegno (viewBox 24), #3 etichetta.
\newcommand{\ForestasDisabilita}[3]{\begin{tikzpicture}[baseline=0pt]
\ifnum#1=1\relax\colorlet{forestas-icona}{sar-blu}\colorlet{forestas-bordo}{sar-blu}\else\colorlet{forestas-icona}{ink-2}\colorlet{forestas-bordo}{ink-3}\fi
% Fascia di altezza fissa (6,5 mm, due righe di etichetta), riquadro dell'icona 11 mm: tutte le
% tipologie hanno la stessa altezza e stanno allineate qualunque sia la lunghezza dell'etichetta.
\fill[forestas-icona](0,11mm)rectangle(16mm,17.5mm);
\node[text=white,font=\fontsize{7.5pt}{8.5pt}\selectfont\bfseries,align=center,text width=15mm,inner sep=0pt]at(8mm,14.25mm){#3};
\ifnum#1=1\relax\draw[forestas-bordo,line width=0.75pt](0,0)rectangle(16mm,11mm);%
\else\draw[forestas-bordo,line width=0.75pt,dash pattern=on 1.5pt off 1.2pt](0,0)rectangle(16mm,11mm);\fi
% Icona del tema (viewBox 24) scalata a 20,4 pt (circa 7,2 mm), centrata nel riquadro.
\begin{scope}[shift={(4.4mm,9.1mm)},scale=0.85,yscale=-1,forestas-icona,line width=1pt,line cap=round,line join=round]#2\end{scope}
\end{tikzpicture}}

% Mappa: una pagina a sé, A4 al vivo, senza testata, margini né piè di pagina (oc:8982). L'immagine
% arriva già nella misura della pagina (features.print.map.*, 210 x 297 mm) e si stende sulla pagina
% intera, centrata (keepaspectratio: un'immagine di proporzioni diverse non si deforma). Si disegna
% sotto la pagina con eso-pic, che non chiede una seconda compilazione. #2 è il credito, d'obbligo per
% la licenza di OpenStreetMap: piccolo, nell'angolo in basso a destra, su fondo bianco.
\newcommand{\ForestasMappaPagina}[2]{\clearpage\thispagestyle{empty}%
\AddToShipoutPictureBG*{\AtPageCenter{\makebox[0pt][c]{\raisebox{-0.5\height}{\includegraphics[width=\paperwidth,height=\paperheight,keepaspectratio]{#1}}}}%
\AtPageLowerLeft{\put(\LenToUnit{\dimexpr\paperwidth-3mm\relax},\LenToUnit{3mm}){\makebox[0pt][r]{\setlength{\fboxsep}{1.5pt}\colorbox{white}{\begingroup\color{ink-2}\fontsize{7pt}{8pt}\selectfont #2\endgroup}}}}}%
\null\clearpage}

% Pagina unica (fronte del foglio della traccia): il contenuto si compone in un box largo quanto lo
% specchio; se è più alto della pagina (avviso, inclusività e nome su due righe insieme) si
% riduce in scala quanto basta, centrato, invece di traboccare in una terza facciata. Dentro il
% minipage si rimettono il \parskip e l'allineamento del corpo, che il minipage azzera. Il box ha
% altezza zero sopra la linea di base (minipage [t]): in testa alla pagina LaTeX gli mette sopra il
% \topskip, che va tolto dal limite.
\newsavebox{\ForestasPaginaBox}
\newenvironment{ForestasPaginaUnica}{\par\noindent\begin{lrbox}{\ForestasPaginaBox}\begin{minipage}[t]{\linewidth}\vspace{0pt}\setlength{\parskip}{6pt plus 2pt}\raggedright}{\end{minipage}\end{lrbox}%
\ifdim\dimexpr\ht\ForestasPaginaBox+\dp\ForestasPaginaBox\relax>\dimexpr\textheight-\topskip-2pt\relax
\makebox[\linewidth][c]{\resizebox*{!}{\dimexpr\textheight-\topskip-2pt\relax}{\usebox{\ForestasPaginaBox}}}%
\else\usebox{\ForestasPaginaBox}\fi\par}

% Dato in forma breve (dati catastali, punteggi di inclusività): una cella di griglia, occhiello sopra e
% valore sotto, con l'interlinea della didascalia (quella del corpo, 19,2 pt, non starebbe in pagina 1).
% \leavevmode: un \color in modo verticale in testa alla cella la farebbe scendere di una riga.
\newcommand{\ForestasDato}[2]{\leavevmode\ForestasOcchiello{#1}\par\begingroup\color{ink}\bfseries #2\par\endgroup}

% Riga di dati brevi in griglia: #1 numero di colonne, #2 le celle (\ForestasDato separati da & e \\).
\newenvironment{ForestasGriglia}[1]{\par\nointerlineskip\vspace{4pt}\ForestasStileDidascalia\setlength{\parskip}{0pt}\noindent\setlength{\tabcolsep}{4pt}\tabularx{\linewidth}{@{}*{#1}{>{\raggedright\arraybackslash}X}@{}}}{\endtabularx\par}

% QR code del rimando alla pagina del sito: #1 l'URL con i caratteri speciali di qrcode escaped.
\newcommand{\ForestasQr}[1]{\qrcode[height=22mm]{#1}}

% Dati con il rimando alla pagina del sito: il contenuto a sinistra, il QR code (#1, l'URL escaped per
% qrcode) a destra. Con #1 vuoto il contenuto prende tutta la larghezza e il QR non c'è.
\NewDocumentEnvironment{ForestasConRimando}{m}{\par\nointerlineskip\vspace{4pt}\noindent
\begin{minipage}[t]{\dimexpr\linewidth-\if\relax\detokenize{#1}\relax 0mm\else 28mm\fi\relax}\vspace{0pt}}{\end{minipage}%
\if\relax\detokenize{#1}\relax\else\hfill\begin{minipage}[t]{22mm}\vspace{0pt}\ForestasQr{#1}\end{minipage}\fi\par}

% Indirizzo del rimando, sotto i dati: #1 l'invito, #2 l'URL leggibile (escaped per LaTeX).
\newcommand{\ForestasIndirizzo}[2]{\par\vspace{6pt}\ForestasStileDidascalia\leavevmode\ForestasOcchiello{#1}\par\begingroup\color{ink}\ForestasStileDidascalia\bfseries #2\par\endgroup}

% Dati in breve: valore grande (titolo sezione, cifre tabellari) con l'etichetta sotto in didascalia.
\newcommand{\ForestasFatto}[2]{\begingroup\color{ink}\ForestasStileTitoloSezione #1\endgroup\newline\begingroup\color{ink-3}\ForestasStileDidascalia #2\endgroup}

% Tabella etichetta/valore (Tabella dei dati): etichette ink-2, valori ink, filetti line-2.
\renewenvironment{Tabella}{%
% Corpo dei token (12 pt, interlinea 1,6): il README vuole 12 pt come corpo minimo dei documenti, e
% la riga di 19,2 pt basta da sola a separare le righe (arraystretch 1).
\par\setlength{\parskip}{0pt}\renewcommand{\arraystretch}{1}%
\ForestasStileCorpo
% \leavevmode prima di \color: in una cella p un \color in modo verticale aggiunge un \parskip.
\tabularx{\linewidth}{@{}>{\leavevmode\color{ink-2}\raggedright\arraybackslash}p{62mm}>{\leavevmode\color{ink}\raggedright\arraybackslash}X@{}}%
}{%
\endtabularx\par
}
\renewcommand{\RigaTabella}[2]{#1 & #2\\\hline}

% Piè di pagina (PieDiPagina): filetto oro in testa, logotipo e numero di pagina, poi il
% copyright RAS in didascalia ink-3. Il credito della mappa sta sotto la mappa.
\setlength{\footskip}{28pt}
\pagestyle{fancy}\fancyhf{}
\renewcommand{\headrulewidth}{0pt}
\renewcommand{\footrulewidth}{0pt}
\fancyfoot[C]{\parbox[t]{\textwidth}{\begingroup\color{sar-oro}\rule{\textwidth}{2.25pt}\endgroup\par\vspace{3pt}%
\ForestasLogotipoSolo{9pt}\hfill\begingroup\color{ink-3}\ForestasStileDidascalia\thepage\endgroup\par\vspace{-1pt}%
\begingroup\color{ink-3}\ForestasStileDidascalia {!! $copyright !!}\endgroup}}
