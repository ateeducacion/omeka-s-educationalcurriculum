<?php
declare(strict_types=1);

namespace Omeka\Entity;

class Job extends AbstractEntity
{
    private $args;

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
}
