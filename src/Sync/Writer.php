<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

use Omeka\Entity\User;
use RuntimeException;

/**
 * Applies the items of a plan through the Omeka API, in batches.
 *
 * First pass: create the new items with their literals and URIs. Second pass, once every target
 * exists: write the links of the new items and the changes of the modified and retired ones. Only
 * the properties that change are replaced; other properties and item sets of an item are kept.
 */
class Writer
{
    const BATCH_SIZE = 100;

    /**
     * Partial update that replaces the values of the cleared properties and appends item sets.
     */
    const APPEND = ['isPartial' => true, 'collectionAction' => 'append'];

    private $api;
    private $entityManager;
    private $authentication;
    private $logger;
    private $batchSize;

    /**
     * @param \Omeka\Api\Manager $api
     * @param \Doctrine\ORM\EntityManager $entityManager Cleared after each batch to keep memory flat
     * @param \Laminas\Authentication\AuthenticationService $authentication Its user owns the new items
     * @param callable|null $logger Receives a progress message after each batch
     */
    public function __construct(
        $api,
        $entityManager,
        $authentication,
        ?callable $logger = null,
        int $batchSize = self::BATCH_SIZE
    ) {
        $this->api = $api;
        $this->entityManager = $entityManager;
        $this->authentication = $authentication;
        $this->logger = $logger;
        $this->batchSize = $batchSize;
    }

    /**
     * @param array $itemIds Ids of the items already in the curriculum item set, by identifier
     * @return array Ids of the curriculum items by identifier, including the new ones
     */
    public function apply(Plan $plan, Structure $structure, array $itemIds): array
    {
        $new = $plan->getNew();
        $this->inBatches($new, 'Created %d of %d new items.', function (array $item) use ($structure, &$itemIds) {
            $itemIds[$item['identifier']] = $this->api->create('items', $this->newItemData($item, $structure))
                ->getContent()->id();
        });

        $updates = [];
        foreach ($new as $item) {
            $links = $this->linkValues($item['values']);
            if ($links) {
                $updates[] = [$itemIds[$item['identifier']], $links, false];
            }
        }
        foreach ($plan->getModified() as $item) {
            $updates[] = [$item['item'], $this->valuesAfter($item['changes']), false];
        }
        foreach ($plan->getRetired() as $item) {
            $updates[] = [$item['item'], $this->valuesAfter($item['changes']), true];
        }
        $this->inBatches($updates, 'Updated %d of %d items.', function (array $update) use ($structure, $itemIds) {
            [$itemId, $values, $retired] = $update;
            $data = ['clear_property_values' => array_map([$structure, 'getPropertyId'], array_keys($values))];
            $data += $this->apiValues($values, $structure, $itemIds);
            if ($retired) {
                $data['o:item_set'] = [['o:id' => $structure->getRetiredItemSetId()]];
            }
            $this->api->update('items', $itemId, $data, [], self::APPEND);
        });

        return $itemIds;
    }

    /**
     * New values by property; a property whose list is empty is only cleared.
     */
    private function valuesAfter(array $changes): array
    {
        $values = [];
        foreach ($changes as $term => $change) {
            $values[$term] = $change['after'];
        }
        return $values;
    }

    private function newItemData(array $item, Structure $structure): array
    {
        $data = ['o:resource_class' => ['o:id' => $structure->getClassId($item['class'])]];
        $templateId = $structure->getTemplateId($item['class']);
        if ($templateId !== null) {
            $data['o:resource_template'] = ['o:id' => $templateId];
        }
        $data['o:item_set'] = [['o:id' => $structure->getCurriculumItemSetId()]];
        if ($item['retired']) {
            $data['o:item_set'][] = ['o:id' => $structure->getRetiredItemSetId()];
        }
        $data['o:is_public'] = true;
        $values = [];
        foreach ($item['values'] as $term => $list) {
            $literals = array_values(array_filter($list, function (array $value) {
                return $value['type'] !== 'resource:item';
            }));
            if ($literals) {
                $values[$term] = $literals;
            }
        }
        return $data + $this->apiValues($values, $structure, []);
    }

    private function linkValues(array $values): array
    {
        $links = [];
        foreach ($values as $term => $list) {
            foreach ($list as $value) {
                if ($value['type'] === 'resource:item') {
                    $links[$term][] = $value;
                }
            }
        }
        return $links;
    }

    private function apiValues(array $values, Structure $structure, array $itemIds): array
    {
        $data = [];
        foreach ($values as $term => $list) {
            foreach ($list as $value) {
                $data[$term][] = $this->apiValue($term, $value, $structure, $itemIds);
            }
        }
        return $data;
    }

    private function apiValue(string $term, array $value, Structure $structure, array $itemIds): array
    {
        $data = ['type' => $value['type'], 'property_id' => $structure->getPropertyId($term)];
        if ($value['type'] === 'resource:item') {
            if (!isset($itemIds[$value['identifier']])) {
                throw new RuntimeException(sprintf('No item for the link to %s.', $value['identifier']));
            }
            $data['value_resource_id'] = $itemIds[$value['identifier']];
        } elseif ($value['type'] === 'uri') {
            $data['@id'] = $value['@id'];
            if (isset($value['o:label'])) {
                $data['o:label'] = $value['o:label'];
            }
        } else {
            $data['@value'] = $value['@value'];
            if (isset($value['@language'])) {
                $data['@language'] = $value['@language'];
            }
        }
        if (!empty($value['@annotation'])) {
            $data['@annotation'] = $this->apiValues($value['@annotation'], $structure, $itemIds);
        }
        return $data;
    }

    /**
     * Clear the entity manager and attach the authenticated user again: Omeka makes it the owner
     * of new items, and a detached user would fail the next flush.
     */
    private function clear(): void
    {
        $identity = $this->authentication->getIdentity();
        $this->entityManager->clear();
        if ($identity !== null) {
            $this->authentication->getStorage()->write(
                $this->entityManager->getReference(User::class, $identity->getId())
            );
        }
    }

    private function inBatches(array $list, string $message, callable $write): void
    {
        $total = count($list);
        foreach (array_chunk($list, $this->batchSize) as $index => $batch) {
            foreach ($batch as $entry) {
                $write($entry);
            }
            $this->clear();
            if ($this->logger) {
                ($this->logger)(sprintf($message, min(($index + 1) * $this->batchSize, $total), $total));
            }
        }
    }
}
