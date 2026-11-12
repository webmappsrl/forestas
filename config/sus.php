<?php

return [

    /*
     * Lo strumento di prova del canale SUS: due rotte, fuori dalle API e dalla loro
     * documentazione, con cui chi integra approva o respinge da solo una domanda
     * trasmessa, come farebbe l'istruttore, e prova cosi' tutti gli esiti della
     * lettura. Si accende sugli ambienti di prova (dev e UAT) e resta spento
     * altrove: dove e' spento le rotte rispondono 404 e la pagina non mostra i
     * pulsanti.
     */
    'test_tools' => (bool) env('SUS_TEST_TOOLS_ENABLED', false),

];
