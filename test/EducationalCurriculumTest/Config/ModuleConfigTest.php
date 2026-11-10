<?php declare(strict_types=1);

namespace EducationalCurriculumTest\Config;

use PHPUnit\Framework\TestCase;

class ModuleConfigTest extends TestCase
{
    public function testModuleConfigRegistersViewsAndTranslations(): void
    {
        $root = dirname(__DIR__, 3);
        $config = require $root . '/config/module.config.php';

        $this->assertContains($root . '/view', $config['view_manager']['template_path_stack']);
        $this->assertSame(
            $root . '/language',
            $config['translator']['translation_file_patterns'][0]['base_dir']
        );
    }

    public function testModuleIniDeclaresSupportedOmekaVersion(): void
    {
        $ini = parse_ini_file(dirname(__DIR__, 3) . '/config/module.ini', true);

        $this->assertSame('EducationalCurriculum', $ini['info']['name']);
        $this->assertSame('^4.2.0', $ini['info']['omeka_version_constraint']);
    }
}
