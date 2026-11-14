<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

/**
 * A resource representation with an id, a label and values by term.
 */
class FakeResource
{
    private $id;
    private $label;
    private $values;

    public function __construct(int $id, string $label = '', array $values = [])
    {
        $this->id = $id;
        $this->label = $label;
        $this->values = $values;
    }

    /**
     * The first value of a property, as text, like the value() of a representation.
     */
    public function value($term)
    {
        return $this->values[$term] ?? null;
    }

    public function id()
    {
        return $this->id;
    }

    public function label()
    {
        return $this->label;
    }
}
