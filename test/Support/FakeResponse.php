<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

class FakeResponse
{
    private $content;
    private $total;

    public function __construct($content, ?int $total = null)
    {
        $this->content = $content;
        $this->total = $total;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function getTotalResults()
    {
        if ($this->total !== null) {
            return $this->total;
        }
        return is_array($this->content) ? count($this->content) : 1;
    }
}
