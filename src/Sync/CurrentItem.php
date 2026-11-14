<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

/**
 * An item of the curriculum item set as it is now, in the same shape as a MappedNode:
 * values of the module properties only, with links translated to node identifiers.
 */
class CurrentItem
{
    private $id;
    private $resourceClass;
    private $values;
    private $retired;

    public function __construct(int $id, ?string $resourceClass, array $values, bool $retired)
    {
        $this->id = $id;
        $this->resourceClass = $resourceClass;
        $this->values = $values;
        $this->retired = $retired;
    }

    /**
     * Internal id of the item in Omeka.
     */
    public function getId(): int
    {
        return $this->id;
    }

    public function getResourceClass(): ?string
    {
        return $this->resourceClass;
    }

    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Whether the item belongs to the item set of retired terms.
     */
    public function isRetired(): bool
    {
        return $this->retired;
    }

    /**
     * The first literal of dcterms:identifier, which locates the item.
     */
    public function getIdentifier(): ?string
    {
        foreach ($this->values['dcterms:identifier'] ?? [] as $value) {
            if (($value['type'] ?? null) === 'literal' && ($value['@value'] ?? '') !== '') {
                return $value['@value'];
            }
        }
        return null;
    }
}
