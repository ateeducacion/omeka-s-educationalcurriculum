<?php

declare(strict_types=1);

namespace EducationalCurriculumTest;

use EducationalCurriculum\Module;
use PHPUnit\Framework\TestCase;

class ModuleTest extends TestCase
{
    public function testGetConfigReturnsModuleConfiguration(): void
    {
        $module = new Module();
        $this->assertSame(include dirname(__DIR__, 2) . '/config/module.config.php', $module->getConfig());
    }
}
