<?php

declare(strict_types=1);

namespace App\Services\Import\Source;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Legge dalla copia del DB Drupal il record completo di un nodo o di un
 * termine: tutte le traduzioni, tutti i campi del suo tipo e le entità che
 * richiama (oc:8900).
 *
 * L'import oggi ne usa solo una parte, quella che davano le API. Il resto è
 * letto lo stesso perché il giorno che servirà un altro dato di Drupal ci sia
 * già, senza scrivere query nuove. La forma del record è in
 * docs/knowledge/copia-database-drupal.md.
 *
 * I campi non sono scritti qui: si leggono dalla configurazione di Drupal
 * (`config`, `field.field.<entità>.<tipo>.<campo>`), quindi un campo aggiunto
 * in Drupal entra nel record da solo.
 *
 * Riferimenti risolti:
 * - file e immagini → la riga di `file_managed` con il suo URL pubblico;
 * - termini e media → il loro record completo, i cui termini e media restano
 *   id (i file sì): un livello solo, o un termine porterebbe i suoi parenti;
 * - nodi → un riassunto (id, tipo, titolo, stato, lingua): il record intero
 *   lo legge l'import del nodo collegato.
 */
class DrupalRecordReader
{
    private const CONNECTION = 'sardegnasentieri';

    /**
     * Tabella base, colonna dell'id e colonna del tipo per ogni entità.
     */
    private const ENTITIES = [
        'node' => ['node_field_data', 'nid', 'type'],
        'taxonomy_term' => ['taxonomy_term_field_data', 'tid', 'vid'],
        'media' => ['media_field_data', 'mid', 'bundle'],
    ];

    /**
     * Campi di base a più valori, che stanno in una tabella propria ma non
     * nella configurazione dei campi.
     */
    private const BASE_FIELDS = [
        'taxonomy_term' => ['parent' => ['type' => 'entity_reference', 'target' => 'taxonomy_term']],
    ];

    /**
     * Campi che non sono dati: commenti e layout delle pagine.
     */
    private const SKIPPED_TYPES = ['comment', 'layout_section'];

    private const SKIPPED_TARGETS = ['layout'];

    /**
     * Colonne delle tabelle dei campi che non sono valori del campo.
     */
    private const FIELD_TABLE_COLUMNS = ['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta'];

    /** @var array<string, array<string, array{type: string, target: string|null}>> */
    private array $definitions = [];

    /** @var array<string, array<string, string>> */
    private array $targetTypes = [];

    /** @var array<string, int>|null */
    private ?array $tables = null;

    /**
     * Il record completo di un nodo. Un nodo assente è un errore, come la 404
     * delle API.
     *
     * @return array<string, mixed>
     */
    public function node(int $nid): array
    {
        $record = $this->nodes([$nid])[$nid] ?? null;

        if ($record === null) {
            throw new \RuntimeException("Nodo {$nid} non trovato nella copia del DB Drupal");
        }

        return $record;
    }

    /**
     * I record completi di più nodi con le stesse query di uno solo: una per
     * tabella di campo. I nodi assenti mancano dal risultato.
     *
     * @param  list<int>  $nids
     * @return array<int, array<string, mixed>>
     */
    public function nodes(array $nids): array
    {
        return $this->entities('node', $nids, 1);
    }

    /**
     * I termini completi di un vocabolario, nell'ordine di Drupal (peso, poi
     * id della traduzione originale).
     *
     * @return array<int, array<string, mixed>>
     */
    public function vocabulary(string $vid): array
    {
        $tids = $this->db()->table('taxonomy_term_field_data')
            ->where('vid', $vid)->where('default_langcode', 1)
            ->orderBy('weight')->orderBy('tid')
            ->pluck('tid')->map(fn ($tid) => (int) $tid)->all();

        $records = $this->entities('taxonomy_term', $tids, 1);

        $ordered = [];
        foreach ($tids as $tid) {
            $ordered[$tid] = $records[$tid];
        }

        return $ordered;
    }

    /**
     * Il record completo di un termine, o null se non c'è.
     *
     * @return array<string, mixed>|null
     */
    public function term(int $tid): ?array
    {
        return $this->entities('taxonomy_term', [$tid], 1)[$tid] ?? null;
    }

    // -------------------------------------------------------------------------

    private function db(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * Record completi di più entità dello stesso tipo, con una query per
     * tabella di campo invece che una per entità.
     *
     * @param  list<int>  $ids
     * @param  int  $depth  quanti livelli di termini e media risolvere
     * @return array<int, array<string, mixed>>
     */
    private function entities(string $entityType, array $ids, int $depth): array
    {
        if ($ids === []) {
            return [];
        }

        [$table, $idColumn, $bundleColumn] = self::ENTITIES[$entityType];

        // Prima la traduzione originale, poi le altre in ordine di lingua.
        $rows = $this->db()->table($table)
            ->whereIn($idColumn, array_values(array_unique($ids)))
            ->orderBy($idColumn)->orderByDesc('default_langcode')->orderBy('langcode')
            ->get();

        $records = [];
        foreach ($rows as $row) {
            $id = (int) $row->{$idColumn};
            $records[$id] ??= [
                'entity_type' => $entityType,
                'id' => $id,
                'bundle' => (string) $row->{$bundleColumn},
                'langcode' => (string) $row->langcode,
                'translations' => [],
                'fields' => [],
            ];
            $records[$id]['translations'][(string) $row->langcode] = (array) $row;
        }

        $byBundle = [];
        foreach ($records as $id => $record) {
            $byBundle[$record['bundle']][] = $id;
        }

        foreach ($byBundle as $bundle => $bundleIds) {
            foreach ($this->definitions($entityType, (string) $bundle) as $field => $definition) {
                foreach ($this->fieldItems($entityType, $field, $bundleIds) as $id => $items) {
                    $records[$id]['fields'][$field] = $items;
                }
            }
        }

        if ($entityType === 'node') {
            $this->attachAliases($records);
        }

        $this->resolveReferences($records, $entityType, $depth);

        return $records;
    }

    /**
     * I valori di un campo, per entità, lingua e posizione.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, array<int, array<string, mixed>>>>
     */
    private function fieldItems(string $entityType, string $field, array $ids): array
    {
        $table = "{$entityType}__{$field}";

        if (! isset($this->tables()[$table])) {
            return [];
        }

        $rows = $this->db()->table($table)
            ->whereIn('entity_id', $ids)->where('deleted', 0)
            ->orderBy('entity_id')->orderBy('delta')->orderBy('langcode')
            ->get();

        $prefix = "{$field}_";
        $items = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ((array) $row as $column => $value) {
                if (! in_array($column, self::FIELD_TABLE_COLUMNS, true)) {
                    $item[str_starts_with($column, $prefix) ? substr($column, strlen($prefix)) : $column] = $value;
                }
            }
            $items[(int) $row->entity_id][(string) $row->langcode][(int) $row->delta] = $item;
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function attachAliases(array &$records): void
    {
        $paths = array_map(fn ($id) => "/node/{$id}", array_keys($records));

        $aliases = $this->db()->table('path_alias')
            ->whereIn('path', $paths)->orderBy('id')
            ->get(['id', 'path', 'alias', 'langcode', 'status']);

        foreach ($records as $id => $record) {
            $records[$id]['aliases'] = [];
        }
        foreach ($aliases as $alias) {
            $id = (int) substr((string) $alias->path, strlen('/node/'));
            $records[$id]['aliases'][] = (array) $alias;
        }
    }

    /**
     * Aggiunge `entity` a ogni valore che richiama un'altra entità.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function resolveReferences(array &$records, string $entityType, int $depth): void
    {
        $wanted = [];
        foreach ($records as $record) {
            $definitions = $this->definitions($entityType, $record['bundle']);
            foreach ($record['fields'] as $field => $languages) {
                $target = $definitions[$field]['target'] ?? null;
                if ($target === null) {
                    continue;
                }
                foreach ($languages as $items) {
                    foreach ($items as $item) {
                        if (isset($item['target_id'])) {
                            $wanted[$target][] = (int) $item['target_id'];
                        }
                    }
                }
            }
        }

        $loaded = [];
        foreach ($wanted as $target => $ids) {
            $ids = array_values(array_unique($ids));
            $loaded[$target] = match (true) {
                $target === 'file' => $this->files($ids),
                $target === 'node' => $this->nodeSummaries($ids),
                $depth > 0 && isset(self::ENTITIES[$target]) => $this->entities($target, $ids, $depth - 1),
                default => [],
            };
        }

        foreach ($records as $id => $record) {
            $definitions = $this->definitions($entityType, $record['bundle']);
            foreach ($record['fields'] as $field => $languages) {
                $target = $definitions[$field]['target'] ?? null;
                if ($target === null) {
                    continue;
                }
                foreach ($languages as $langcode => $items) {
                    foreach ($items as $delta => $item) {
                        $entity = isset($item['target_id']) ? ($loaded[$target][(int) $item['target_id']] ?? null) : null;
                        if ($entity !== null) {
                            $records[$id]['fields'][$field][$langcode][$delta]['entity'] = $entity;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  list<int>  $fids
     * @return array<int, array<string, mixed>>
     */
    private function files(array $fids): array
    {
        $files = [];
        foreach ($this->db()->table('file_managed')->whereIn('fid', $fids)->get() as $row) {
            $file = (array) $row;
            $file['url'] = DrupalValues::fileUrl((string) $row->uri);
            $files[(int) $row->fid] = $file;
        }

        return $files;
    }

    /**
     * @param  list<int>  $nids
     * @return array<int, array<string, mixed>>
     */
    private function nodeSummaries(array $nids): array
    {
        return $this->db()->table('node_field_data')
            ->whereIn('nid', $nids)->where('default_langcode', 1)
            ->get(['nid', 'type', 'title', 'status', 'langcode'])
            ->mapWithKeys(fn ($row) => [(int) $row->nid => (array) $row])
            ->all();
    }

    /**
     * I campi di un tipo di entità, con il tipo di campo e l'entità che
     * richiamano (`file` per file e immagini).
     *
     * @return array<string, array{type: string, target: string|null}>
     */
    private function definitions(string $entityType, string $bundle): array
    {
        $key = "{$entityType}.{$bundle}";

        if (isset($this->definitions[$key])) {
            return $this->definitions[$key];
        }

        $definitions = self::BASE_FIELDS[$entityType] ?? [];

        $configs = $this->db()->table('config')
            ->where('collection', '')->where('name', 'like', "field.field.{$key}.%")
            ->orderBy('name')->pluck('data');

        foreach ($configs as $data) {
            $config = unserialize((string) $data, ['allowed_classes' => false]);
            if (! is_array($config) || ! isset($config['field_name'], $config['field_type'])) {
                continue;
            }

            $type = (string) $config['field_type'];
            $target = match ($type) {
                'image', 'file' => 'file',
                'entity_reference', 'entity_reference_revisions' => $this->targetTypes($entityType)[(string) $config['field_name']] ?? null,
                default => null,
            };

            if (in_array($type, self::SKIPPED_TYPES, true) || in_array($target, self::SKIPPED_TARGETS, true)) {
                continue;
            }

            $definitions[(string) $config['field_name']] = ['type' => $type, 'target' => $target];
        }

        return $this->definitions[$key] = $definitions;
    }

    /**
     * L'entità richiamata da ogni campo di riferimento, letta dallo storage
     * del campo (`field.storage.<entità>.<campo>`).
     *
     * @return array<string, string>
     */
    private function targetTypes(string $entityType): array
    {
        if (isset($this->targetTypes[$entityType])) {
            return $this->targetTypes[$entityType];
        }

        $targets = [];
        $configs = $this->db()->table('config')
            ->where('collection', '')->where('name', 'like', "field.storage.{$entityType}.%")
            ->pluck('data');

        foreach ($configs as $data) {
            $config = unserialize((string) $data, ['allowed_classes' => false]);
            $target = is_array($config) ? ($config['settings']['target_type'] ?? null) : null;
            if (is_string($target) && isset($config['field_name'])) {
                $targets[(string) $config['field_name']] = $target;
            }
        }

        return $this->targetTypes[$entityType] = $targets;
    }

    /**
     * Le tabelle della copia, come chiavi: un campo configurato può non
     * avere la sua.
     *
     * @return array<string, int>
     */
    private function tables(): array
    {
        return $this->tables ??= collect($this->db()->select('SHOW TABLES'))
            ->map(fn ($row) => (string) array_values((array) $row)[0])
            ->flip()->all();
    }
}
