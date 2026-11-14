<?php
declare(strict_types=1);

namespace EducationalCurriculum\Stdlib;

/**
 * An exception message shown to people: it keeps its template and arguments so the page can
 * translate the template and then fill it in, like Omeka\Stdlib\Message.
 */
trait TranslatableMessage
{
    private $template;
    private $args;

    public function __construct(string $template, ...$args)
    {
        $this->template = $template;
        $this->args = $args;
        parent::__construct($args ? vsprintf($template, $args) : $template);
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getArgs(): array
    {
        return $this->args;
    }
}
