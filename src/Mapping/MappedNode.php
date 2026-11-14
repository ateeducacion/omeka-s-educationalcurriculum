<?php
declare(strict_types=1);

namespace EducationalCurriculum\Mapping;

/**
 * The item a node should be in Omeka: class, values of the module properties and retirement.
 */
class MappedNode
{
    private $identifier;
    private $resourceClass;
    private $values;
    private $retired;

    public function __construct(string $identifier, string $resourceClass, array $values, bool $retired)
    {
        $this->identifier = $identifier;
        $this->resourceClass = $resourceClass;
        $this->values = $values;
        $this->retired = $retired;
    }

    /**
     * Node id, e.g. crit:SMAT01CE1.1: the literal of dcterms:identifier.
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Resource class term, e.g. cur:CriterioEvaluacion.
     */
    public function getResourceClass(): string
    {
        return $this->resourceClass;
    }

    /**
     * Values by property term, in NodeMapper::PROPERTIES order; properties without values are absent.
     *
     * Each value is a literal (type, @value, @language and an optional @annotation), a uri
     * (type, @id, o:label) or a link to another node (type resource:item, identifier).
     *
     * @return array[]
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Whether the item belongs in the item set of retired terms.
     */
    public function isRetired(): bool
    {
        return $this->retired;
    }
}
