<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

/**
 * Ids of what the curriculum items are written with: properties, classes, templates and item sets.
 */
class Structure
{
    private $propertyIds;
    private $classIds;
    private $templateIds;
    private $curriculumItemSetId;
    private $retiredItemSetId;

    public function __construct(
        array $propertyIds,
        array $classIds,
        array $templateIds,
        int $curriculumItemSetId,
        int $retiredItemSetId
    ) {
        $this->propertyIds = $propertyIds;
        $this->classIds = $classIds;
        $this->templateIds = $templateIds;
        $this->curriculumItemSetId = $curriculumItemSetId;
        $this->retiredItemSetId = $retiredItemSetId;
    }

    public function getPropertyId(string $term): int
    {
        if (!isset($this->propertyIds[$term])) {
            throw new StructureException(sprintf('The property %s is not installed.', $term));
        }
        return $this->propertyIds[$term];
    }

    public function getClassId(string $term): int
    {
        if (!isset($this->classIds[$term])) {
            throw new StructureException(sprintf('The resource class %s is not installed.', $term));
        }
        return $this->classIds[$term];
    }

    public function getTemplateId(string $classTerm): ?int
    {
        return $this->templateIds[$classTerm] ?? null;
    }

    public function getCurriculumItemSetId(): int
    {
        return $this->curriculumItemSetId;
    }

    public function getRetiredItemSetId(): int
    {
        return $this->retiredItemSetId;
    }
}
