<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

/**
 * Request parameters, like Laminas\Stdlib\Parameters.
 */
class FakeParameters
{
    private $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function toArray()
    {
        return $this->values;
    }
}
