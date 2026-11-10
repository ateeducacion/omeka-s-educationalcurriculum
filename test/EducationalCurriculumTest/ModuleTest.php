<?php

declare(strict_types=1);

namespace EducationalCurriculumTest;

use EducationalCurriculum\Module;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class ModuleTest extends TestCase
{
    private $connection;
    private $filesPath;
    private $services;
    private $module;

    protected function setUp(): void
    {
        $this->connection = new class {
            public $statements = [];

            public function executeStatement($sql)
            {
                $this->statements[] = $sql;
                return 0;
            }
        };
        $this->filesPath = sys_get_temp_dir() . '/educationalcurriculum-test-' . uniqid();
        mkdir($this->filesPath);
        $this->services = new ServiceManager(['services' => [
            'Omeka\Connection' => $this->connection,
            'Config' => ['file_store' => ['local' => ['base_path' => $this->filesPath]]],
        ]]);
        $this->module = new Module();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->filesPath));
    }

    public function testGetConfigReturnsModuleConfiguration(): void
    {
        $this->assertSame(include dirname(__DIR__, 2) . '/config/module.config.php', $this->module->getConfig());
    }

    public function testInstallCreatesImportTable(): void
    {
        $this->module->install($this->services);

        $this->assertCount(4, $this->connection->statements);
        $this->assertStringStartsWith('CREATE TABLE curriculum_import ', $this->connection->statements[0]);
        foreach (array_slice($this->connection->statements, 1) as $sql) {
            $this->assertStringStartsWith('ALTER TABLE curriculum_import ADD CONSTRAINT', $sql);
            $this->assertStringEndsWith('ON DELETE SET NULL', $sql);
        }
    }

    public function testFilesPathIsInsideTheFileStore(): void
    {
        $this->assertSame($this->filesPath . '/educationalcurriculum', $this->module->getFilesPath($this->services));
    }

    public function testUninstallDropsTableAndImportFiles(): void
    {
        $outside = $this->filesPath . '/outside';
        mkdir($outside);
        touch($outside . '/keep.txt');
        $importDir = $this->filesPath . '/educationalcurriculum/1';
        mkdir($importDir, 0777, true);
        file_put_contents($importDir . '/curriculo.json', '{}');
        symlink($outside, $importDir . '/link');

        $this->module->uninstall($this->services);

        $this->assertSame(['DROP TABLE IF EXISTS curriculum_import'], $this->connection->statements);
        $this->assertDirectoryDoesNotExist($this->filesPath . '/educationalcurriculum');
        $this->assertFileExists($outside . '/keep.txt');
    }

    public function testUninstallWithoutFilesOnlyDropsTable(): void
    {
        $this->module->uninstall($this->services);

        $this->assertSame(['DROP TABLE IF EXISTS curriculum_import'], $this->connection->statements);
    }
}
