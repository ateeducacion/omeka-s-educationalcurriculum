<?php
declare(strict_types=1);

namespace Omeka\Job;

use Omeka\Entity\Job;

abstract class AbstractJob
{
    protected $serviceLocator;
    protected $job;

    public function __construct(Job $job, $serviceLocator)
    {
        $this->job = $job;
        $this->serviceLocator = $serviceLocator;
    }

    public function getArg($name, $default = null)
    {
        $args = $this->job->getArgs();
        return is_array($args) && array_key_exists($name, $args) ? $args[$name] : $default;
    }

    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    abstract public function perform();
}
