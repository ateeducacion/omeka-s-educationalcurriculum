<?php
declare(strict_types=1);

namespace Omeka\Entity;

class Job extends AbstractEntity
{
    private $args;
    private $status = 'starting';

    public function getId()
    {
        return null;
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
