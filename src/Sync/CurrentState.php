<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

use EducationalCurriculum\Mapping\NodeMapper;

/**
 * Reads the items of the curriculum item set with read-only queries, in the shape of NodeMapper.
 *
 * Reading some 15,000 items with their values through the API is too slow; reading does not
 * need its events or validation.
 */
class CurrentState
{
    private $connection;

    /**
     * @param \Doctrine\DBAL\Connection $connection
     */
    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param int $itemSetId The curriculum item set
     * @param int|null $retiredItemSetId The item set of retired terms, if it exists
     * @return CurrentItem[] Ordered by item id
     */
    public function read(int $itemSetId, ?int $retiredItemSetId): array
    {
        $items = $this->connection->fetchAllAssociative(
            'SELECT r.id, CONCAT(v.prefix, \':\', rc.local_name) AS class
            FROM item_item_set iis
            JOIN resource r ON r.id = iis.item_id
            LEFT JOIN resource_class rc ON rc.id = r.resource_class_id
            LEFT JOIN vocabulary v ON v.id = rc.vocabulary_id
            WHERE iis.item_set_id = ?
            ORDER BY r.id',
            [$itemSetId]
        );
        $retired = [];
        if ($retiredItemSetId !== null) {
            $retired = array_flip(array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT item_id FROM item_item_set WHERE item_set_id = ?',
                [$retiredItemSetId]
            )));
        }
        $valueRows = $this->connection->fetchAllAssociative(
            'SELECT val.resource_id, CONCAT(voc.prefix, \':\', p.local_name) AS term, val.type, val.value,
                val.uri, val.lang, val.value_resource_id, val.value_annotation_id
            FROM value val
            JOIN item_item_set iis ON iis.item_id = val.resource_id
            JOIN property p ON p.id = val.property_id
            JOIN vocabulary voc ON voc.id = p.vocabulary_id
            WHERE iis.item_set_id = ?
            ORDER BY val.resource_id, val.id',
            [$itemSetId]
        );
        $annotationRows = $this->connection->fetchAllAssociative(
            'SELECT val.value_annotation_id AS annotation_id, CONCAT(voc.prefix, \':\', p.local_name) AS term,
                av.type, av.value, av.uri, av.lang, av.value_resource_id
            FROM value val
            JOIN item_item_set iis ON iis.item_id = val.resource_id
            JOIN value av ON av.resource_id = val.value_annotation_id
            JOIN property p ON p.id = av.property_id
            JOIN vocabulary voc ON voc.id = p.vocabulary_id
            WHERE iis.item_set_id = ?
            ORDER BY av.resource_id, av.id',
            [$itemSetId]
        );

        $identifiers = $this->identifiers($valueRows);
        $annotations = [];
        foreach ($annotationRows as $row) {
            $annotations[(int) $row['annotation_id']][$row['term']][] = $this->value($row, $identifiers);
        }
        $values = [];
        $properties = array_flip(NodeMapper::PROPERTIES);
        foreach ($valueRows as $row) {
            if (!isset($properties[$row['term']])) {
                continue;
            }
            $value = $this->value($row, $identifiers);
            if ($row['value_annotation_id'] !== null && isset($annotations[(int) $row['value_annotation_id']])) {
                $value['@annotation'] = $annotations[(int) $row['value_annotation_id']];
            }
            $values[(int) $row['resource_id']][$row['term']][] = $value;
        }

        $current = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $itemValues = $this->sortByProperty($values[$id] ?? []);
            $current[] = new CurrentItem($id, $item['class'], $itemValues, isset($retired[$id]));
        }
        return $current;
    }

    /**
     * Terms of an installed vocabulary, classes first, with the given prefix (cur:Etapa…).
     *
     * @return string[]
     */
    public function vocabularyTerms(string $namespaceUri, string $prefix): array
    {
        $classes = $this->connection->fetchFirstColumn(
            'SELECT rc.local_name FROM resource_class rc JOIN vocabulary v ON v.id = rc.vocabulary_id
            WHERE v.namespace_uri = ? ORDER BY rc.id',
            [$namespaceUri]
        );
        $properties = $this->connection->fetchFirstColumn(
            'SELECT p.local_name FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id
            WHERE v.namespace_uri = ? ORDER BY p.id',
            [$namespaceUri]
        );
        return array_map(function ($localName) use ($prefix) {
            return $prefix . ':' . $localName;
        }, array_merge($classes, $properties));
    }

    /**
     * Literal identifier of each item of the set, to translate links into node identifiers.
     */
    private function identifiers(array $valueRows): array
    {
        $identifiers = [];
        foreach ($valueRows as $row) {
            $id = (int) $row['resource_id'];
            if ($row['term'] === 'dcterms:identifier' && $row['type'] === 'literal' && !isset($identifiers[$id])) {
                $identifiers[$id] = $row['value'];
            }
        }
        return $identifiers;
    }

    private function value(array $row, array $identifiers): array
    {
        if ($row['type'] === 'literal') {
            return ['type' => 'literal', '@value' => $row['value'], '@language' => $row['lang'] ?: null];
        }
        if ($row['type'] === 'uri') {
            return ['type' => 'uri', '@id' => $row['uri'], 'o:label' => $row['value']];
        }
        if ($row['value_resource_id'] !== null) {
            $target = (int) $row['value_resource_id'];
            if (isset($identifiers[$target])) {
                return ['type' => $row['type'], 'identifier' => $identifiers[$target]];
            }
            return ['type' => $row['type'], 'identifier' => null, 'item' => $target];
        }
        return ['type' => $row['type'], '@value' => $row['value'], '@id' => $row['uri']];
    }

    private function sortByProperty(array $values): array
    {
        $sorted = [];
        foreach (NodeMapper::PROPERTIES as $term) {
            if (isset($values[$term])) {
                $sorted[$term] = $values[$term];
            }
        }
        return $sorted;
    }
}
