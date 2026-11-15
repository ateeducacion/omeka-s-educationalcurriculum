<?php
declare(strict_types=1);

namespace Omeka\Entity;

class Job extends AbstractEntity
{
    private $id;
    private $args;
    private $status = 'starting';

    public function __construct(?int $id = null)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setArgs($args)
    {
        $this->args = $args;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getStatus()
    {
        return $this->status;
    }
}
