<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

use Omeka\Entity\Job;

/**
 * Records dispatched jobs and returns a job entity for each.
 */
class FakeDispatcher
{
    public $dispatched = [];

    public function dispatch($class, $args = null)
    {
        $this->dispatched[] = [$class, $args];
        $job = new Job();
        $job->setArgs($args);
        return $job;
    }
}
