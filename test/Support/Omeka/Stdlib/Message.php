<?php
declare(strict_types=1);

namespace Omeka\Stdlib;

class Message
{
    private $message;
    private $args;

    public function __construct($message, ...$args)
    {
        $this->message = $message;
        $this->args = $args;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function getArgs()
    {
        return $this->args;
    }
}
