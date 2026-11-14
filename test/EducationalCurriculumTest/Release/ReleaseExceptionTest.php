<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Release;

use EducationalCurriculum\Admin\ImportException;
use EducationalCurriculum\Release\ReleaseException;
use PHPUnit\Framework\TestCase;

class ReleaseExceptionTest extends TestCase
{
    public function testKeepsTheTemplateAndArgumentsForTranslation(): void
    {
        $e = new ReleaseException('Version %s is older than the loaded version %s.', '1.1.2', '1.2.0');

        $this->assertSame('Version 1.1.2 is older than the loaded version 1.2.0.', $e->getMessage());
        $this->assertSame('Version %s is older than the loaded version %s.', $e->getTemplate());
        $this->assertSame(['1.1.2', '1.2.0'], $e->getArgs());
    }

    public function testAMessageWithoutArgumentsIsItsOwnTemplate(): void
    {
        $e = new ImportException('This import cannot be analyzed now.');

        $this->assertSame('This import cannot be analyzed now.', $e->getMessage());
        $this->assertSame('This import cannot be analyzed now.', $e->getTemplate());
        $this->assertSame([], $e->getArgs());
    }
}
