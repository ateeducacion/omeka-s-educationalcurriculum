<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

class FakeResponse
{
    private $content;

    public function __construct($content)
    {
        $this->content = $content;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getTotalResults()
    {
        return is_array($this->content) ? count($this->content) : 1;
    }
}
