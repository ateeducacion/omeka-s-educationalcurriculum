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
    private $nextId = 1;
    private $synchronous;

    /**
     * @param callable|null $synchronous Runs the job inside dispatch(), as Omeka's synchronous
     *   strategy does; that strategy then clears the entity manager
     */
    public function __construct(?callable $synchronous = null)
    {
        $this->synchronous = $synchronous;
    }

    public function dispatch($class, $args = null)
    {
        $this->dispatched[] = [$class, $args];
        $job = new Job($this->nextId++);
        $job->setArgs($args);
        if ($this->synchronous) {
            ($this->synchronous)($class, $args);
        }
        return $job;
    }
}
