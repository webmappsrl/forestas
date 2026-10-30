<?php

declare(strict_types=1);

namespace App\Services\Import\Source;

use App\Dto\Api\ApiPoiResponse;
use App\Dto\Api\ApiTrackResponse;

/**
 * Da dove l'import legge i dati di Sardegna Sentieri.
 *
 * Ogni metodo restituisce la stessa struttura delle API di Drupal: DTO,
 * service, job e comando non sanno quale fonte c'è dietro, e il cambio di
 * fonte non tocca ciò che l'import scrive (oc:8900). Il download dei file
 * (GPX, immagini) non passa da qui: resta su SardegnaSentieriClient.
 */
interface SardegnaSentieriSource
{
    /**
     * La fonte ha dati? Si chiede prima di un `--reset`, che tronca e solo
     * dopo riscarica: il controllo deve chiedere i dati, non la sola
     * connessione (incidente del 10/09/2026).
     */
    public function isAvailable(): bool;

    /**
     * @return array<string, string> {nid: 'Y-m-d H:i:s' in ora italiana}
     */
    public function getPoiList(): array;

    public function getPoiDetail(int $id): ApiPoiResponse;

    /**
     * @return array<string, string> {nid: 'Y-m-d H:i:s' in ora italiana}
     */
    public function getTrackList(): array;

    public function getTrackDetail(int $id): ApiTrackResponse;

    /**
     * Gli enti (soggetti gestori, manutentori, rilevatori, operatori,
     * complessi forestali), con la data di modifica come per sentieri e POI
     * (oc:8513).
     *
     * @return array<string, string> {nid: 'Y-m-d H:i:s' in ora italiana}
     */
    public function getEnteList(): array;

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getTaxonomy(string $vocabulary): array;

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getTaxonomyWarnings(): array;

    /**
     * Nodo Drupal nella forma del REST `/node/{id}` (usato per gli enti).
     *
     * @return array<string, mixed>
     */
    public function getNodeDetail(int $id): array;

    /**
     * Termine nella forma del REST `/taxonomy/term/{id}`.
     *
     * @return array<string, mixed>
     */
    public function getTaxonomyTerm(int $id): array;
}
