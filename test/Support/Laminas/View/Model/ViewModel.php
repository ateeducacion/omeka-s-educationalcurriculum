<?php
declare(strict_types=1);

namespace Laminas\View\Model;

class ViewModel
{
    private $variables;

    public function __construct($variables = [], $options = null)
    {
        $this->variables = $variables;
    }

    public function getVariables()
    {
        return $this->variables;
    }

    public function getVariable($name, $default = null)
    {
        return $this->variables[$name] ?? $default;
    }
}
