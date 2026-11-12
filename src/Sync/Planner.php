<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

use EducationalCurriculum\Mapping\MappedNode;
use EducationalCurriculum\Mapping\NodeMapper;

/**
 * Compares the items a release wants with the items Omeka has and plans the changes.
 */
class Planner
{
    /**
     * @param string $version Version of the release
     * @param MappedNode[] $desired Items of the release, in corpus order
     * @param CurrentItem[] $current Items of the curriculum item set
     * @param string[] $currentVocabulary Terms of the installed cur vocabulary (cur:Etapa, cur:etapa…)
     * @param string[] $newVocabulary Terms of the cur.ttl of the release
     */
    public function plan(
        string $version,
        array $desired,
        array $current,
        array $currentVocabulary,
        array $newVocabulary
    ): Plan {
        $errors = [];
        $nodes = [];
        foreach ($desired as $node) {
            $nodes[$node->getIdentifier()] = $node;
        }

        $itemsByIdentifier = [];
        foreach ($current as $item) {
            $identifier = $item->getIdentifier();
            if ($identifier === null) {
                $errors[] = $this->error(Plan::ERROR_MISSING_IDENTIFIER, null, $item->getId());
                continue;
            }
            $itemsByIdentifier[$identifier][] = $item;
        }
        $matched = [];
        foreach ($itemsByIdentifier as $identifier => $items) {
            $identifier = (string) $identifier;
            if (count($items) > 1) {
                foreach ($items as $item) {
                    $errors[] = $this->error(Plan::ERROR_DUPLICATE_IDENTIFIER, $identifier, $item->getId());
                }
            } elseif (!isset($nodes[$identifier])) {
                $errors[] = $this->error(Plan::ERROR_UNKNOWN_IDENTIFIER, $identifier, $items[0]->getId());
            } else {
                $matched[$identifier] = $items[0];
            }
        }

        foreach ($desired as $node) {
            foreach ($node->getValues() as $term => $values) {
                foreach ($values as $value) {
                    if ($value['type'] === 'resource:item' && !isset($nodes[$value['identifier']])) {
                        $errors[] = $this->error(Plan::ERROR_UNKNOWN_LINK, $node->getIdentifier(), null, [
                            'property' => $term,
                            'target' => $value['identifier'],
                        ]);
                    }
                }
            }
        }

        $summary = [];
        $new = [];
        $modified = [];
        $retired = [];
        foreach ($desired as $node) {
            $identifier = $node->getIdentifier();
            $class = $node->getResourceClass();
            $summary += [$class => ['new' => 0, 'modified' => 0, 'retired' => 0, 'unchanged' => 0]];
            if (isset($itemsByIdentifier[$identifier]) && !isset($matched[$identifier])) {
                continue;
            }
            $item = $matched[$identifier] ?? null;
            if ($item === null) {
                $new[] = [
                    'identifier' => $identifier,
                    'class' => $class,
                    'retired' => $node->isRetired(),
                    'values' => $node->getValues(),
                ];
                $summary[$class]['new']++;
                continue;
            }
            if ($item->getResourceClass() !== $class) {
                $errors[] = $this->error(Plan::ERROR_CLASS_MISMATCH, $identifier, $item->getId(), [
                    'current' => $item->getResourceClass(),
                    'expected' => $class,
                ]);
                continue;
            }
            if ($item->isRetired() && !$node->isRetired()) {
                $errors[] = $this->error(Plan::ERROR_UNRETIRED, $identifier, $item->getId());
                continue;
            }

            $changes = $this->changes($this->desiredValues($node, $item), $item->getValues());
            $change = ['identifier' => $identifier, 'item' => $item->getId(), 'class' => $class, 'changes' => $changes];
            if ($node->isRetired() && !$item->isRetired()) {
                $retired[] = $change;
                $summary[$class]['retired']++;
            } elseif ($changes !== []) {
                $modified[] = $change;
                $summary[$class]['modified']++;
            } else {
                $summary[$class]['unchanged']++;
            }
        }
        ksort($summary);

        $vocabulary = [
            'added' => array_values(array_diff($newVocabulary, $currentVocabulary)),
            'removed' => array_values(array_diff($currentVocabulary, $newVocabulary)),
        ];
        $used = $this->curTerms($current);
        foreach ($vocabulary['removed'] as $term) {
            if (in_array($term, $used, true)) {
                $errors[] = $this->error(Plan::ERROR_VOCABULARY_TERM_REMOVED, null, null, ['term' => $term]);
            }
        }
        foreach ($this->curTerms($desired) as $term) {
            if (!in_array($term, $newVocabulary, true) && !in_array($term, $vocabulary['removed'], true)) {
                $errors[] = $this->error(Plan::ERROR_VOCABULARY_TERM_MISSING, null, null, ['term' => $term]);
            }
        }

        return new Plan($version, $summary, $new, $modified, $retired, $vocabulary, $errors);
    }

    /**
     * Values the item should have. An item retired in an earlier release keeps its note,
     * which names the version where it was retired.
     */
    private function desiredValues(MappedNode $node, CurrentItem $item): array
    {
        $values = $node->getValues();
        $note = $item->getValues()['skos:changeNote'] ?? [];
        if ($node->isRetired() && $item->isRetired() && $note !== []) {
            $values['skos:changeNote'] = $note;
        }
        return $values;
    }

    /**
     * Properties whose list of values differs, order included; each one is replaced whole.
     */
    private function changes(array $after, array $before): array
    {
        $changes = [];
        foreach (NodeMapper::PROPERTIES as $term) {
            $new = $after[$term] ?? [];
            $old = $before[$term] ?? [];
            if ($this->normalize($new) !== $this->normalize($old)) {
                $changes[$term] = ['before' => $old, 'after' => $new];
            }
        }
        return $changes;
    }

    /**
     * Sorts the keys of every value so that two equal values compare equal.
     */
    private function normalize(array $data): array
    {
        if (array_values($data) !== $data) {
            ksort($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalize($value);
            }
        }
        return $data;
    }

    /**
     * Terms of the cur vocabulary used as classes, properties or value annotations.
     *
     * @param MappedNode[]|CurrentItem[] $items
     * @return string[]
     */
    private function curTerms(array $items): array
    {
        $terms = [];
        foreach ($items as $item) {
            $terms[] = (string) $item->getResourceClass();
            foreach ($item->getValues() as $term => $values) {
                $terms[] = $term;
                foreach ($values as $value) {
                    foreach (array_keys($value['@annotation'] ?? []) as $annotationTerm) {
                        $terms[] = $annotationTerm;
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($terms, function ($term) {
            return strpos($term, 'cur:') === 0;
        })));
    }

    private function error(string $code, ?string $identifier, ?int $item, array $detail = []): array
    {
        return ['code' => $code, 'identifier' => $identifier, 'item' => $item, 'detail' => $detail];
    }
}
