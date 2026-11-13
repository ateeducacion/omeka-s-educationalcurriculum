<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

/**
 * A resource representation with an id and a label.
 */
class FakeResource
{
    private $id;
    private $label;

    public function __construct(int $id, string $label = '')
    {
        $this->id = $id;
        $this->label = $label;
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
