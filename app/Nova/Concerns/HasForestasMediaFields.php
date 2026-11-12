<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use Ebess\AdvancedNovaMediaLibrary\Fields\Files;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Ebess\AdvancedNovaMediaLibrary\Fields\Media;
use Wm\WmPackage\Nova\Fields\ImageCredits;

/**
 * I tre campi media dei contenuti importati da Sardegna Sentieri, distinti per tipo di file
 * (oc:8932): immagini, documenti, video.
 *
 * Immagine principale e galleria restano nello stesso campo perché condividono la collection
 * `default` e si distinguono per ordine: separarle richiederebbe di spostare i media esistenti.
 *
 * Tutti e tre permettono di scegliere un file già caricato (`enableExistingMedia()`, acceso da
 * `config/nova-media-library.php`): su Drupal lo stesso file è riferito da più contenuti.
 *
 * Trappole (wm-package/.claude/rules/nova.md): sui campi media si usa `singleMediaRules()`, mai
 * `rules()`, che valida l'intero array della collection e blocca ogni salvataggio del form;
 * `Images` impone `image`, che con Laravel 12 esclude gli SVG, quindi documenti e video stanno
 * su `Files`.
 */
trait HasForestasMediaFields
{
    /**
     * Immagini (collection `default`), documenti (`attachments`) e video (`videos`).
     *
     * @return array<int, Media>
     */
    protected function forestasMediaFields(): array
    {
        return [
            // Didascalia, autore e credits arrivano da Drupal e vivono nelle custom properties del
            // media: sono i campi del package, che li porta con la foto nel dettaglio (oc:8959).
            // Solo sulle immagini: allegati e video non ne hanno (oc:8932).
            $this->withForestasMediaOptions(Images::make(__('Images'), 'default'))
                ->customPropertiesFields(ImageCredits::fields()),
            $this->withForestasMediaOptions(Files::make(__('Documents'), 'attachments')),
            $this->withForestasMediaOptions(Files::make(__('Videos'), 'videos')),
        ];
    }

    /**
     * Le opzioni comuni: scelta fra i file già presenti e niente colonna nell'elenco.
     *
     * @template T of Images
     *
     * @param  T  $field
     * @return T
     */
    /**
     * Toglie dai campi del package il campo immagini (`AbstractEcResource`), riconosciuto dalla
     * collection `default` e non dall'etichetta, che tradotta coincide con la nostra: senza
     * questo filtro le stesse foto
     * comparivano due volte nella pagina. Si tiene quello di forestas, perche' sta nel tab
     * insieme ad Allegati e Video e perche' ha `enableExistingMedia()`: in Drupal la stessa
     * immagine e' richiamata da piu' contenuti (813 nella galleria, 221 fra le principali), e
     * senza quel pulsante la redazione puo' solo ricaricarla (oc:8932).
     *
     * @param  array<int, mixed>  $fields
     * @return array<int, mixed>
     */
    protected function withoutPackageImageField(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn ($field) => ! ($field instanceof Images && $field->attribute === 'default')
        ));
    }

    private function withForestasMediaOptions(Images $field): Images
    {
        $field->enableExistingMedia()->hideFromIndex();

        return $field;
    }
}
