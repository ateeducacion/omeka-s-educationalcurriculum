<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

use ReflectionProperty;

/**
 * Keeps entities in memory, assigns ids on flush and answers findBy on their getters.
 */
class FakeEntityManager
{
    public $flushes = 0;
    private $entities = [];
    private $pending = [];
    private $nextId = 1;

    /**
     * Store an entity as if it had been saved earlier.
     */
    public function add($entity, int $id)
    {
        $this->setId($entity, $id);
        $this->entities[get_class($entity)][$id] = $entity;
        $this->nextId = max($this->nextId, $id + 1);
        return $entity;
    }

    public function persist($entity)
    {
        $this->pending[] = $entity;
    }

    public function remove($entity)
    {
        unset($this->entities[get_class($entity)][$entity->getId()]);
    }

    public function flush()
    {
        foreach ($this->pending as $entity) {
            if ($entity->getId() === null) {
                $this->add($entity, $this->nextId);
            }
        }
        $this->pending = [];
        $this->flushes++;
    }

    public function find($class, $id)
    {
        return $this->entities[$class][(int) $id] ?? null;
    }

    public function getRepository($class)
    {
        $entities = $this->entities[$class] ?? [];
        return new class ($entities) {
            private $entities;

            public function __construct(array $entities)
            {
                $this->entities = $entities;
            }

            public function findBy(array $criteria, ?array $orderBy = null)
            {
                $found = array_values(array_filter($this->entities, function ($entity) use ($criteria) {
                    foreach ($criteria as $field => $value) {
                        $actual = $entity->{'get' . ucfirst($field)}();
                        if (is_array($value) ? !in_array($actual, $value, true) : $actual !== $value) {
                            return false;
                        }
                    }
                    return true;
                }));
                if (($orderBy['id'] ?? null) === 'DESC') {
                    usort($found, function ($a, $b) {
                        return $b->getId() <=> $a->getId();
                    });
                }
                return $found;
            }
        };
    }

    private function setId($entity, int $id): void
    {
        $property = new ReflectionProperty(get_class($entity), 'id');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($entity, $id);
    }
}
