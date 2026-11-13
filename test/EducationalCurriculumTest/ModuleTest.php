<?php

declare(strict_types=1);

namespace EducationalCurriculumTest;

use EducationalCurriculum\Module;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class ModuleTest extends TestCase
{
    private $connection;
    private $api;
    private $rdfImporter;
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
        $this->api = new class {
            public $vocabularies = [];

            public function search($resource, array $query)
            {
                $total = count(array_filter($this->vocabularies, function ($namespace) use ($query) {
                    return $namespace === $query['namespace_uri'];
                }));
                return new class ($total) {
                    private $total;

                    public function __construct(int $total)
                    {
                        $this->total = $total;
                    }

                    public function getTotalResults()
                    {
                        return $this->total;
                    }
                };
            }
        };
        $this->rdfImporter = new class {
            public $imports = [];

            public function import($strategy, array $vocab, array $options = [])
            {
                $this->imports[] = [$strategy, $vocab, $options];
            }
        };
        $this->filesPath = sys_get_temp_dir() . '/educationalcurriculum-test-' . uniqid();
        mkdir($this->filesPath);
        $this->services = new ServiceManager(['services' => [
            'Omeka\Connection' => $this->connection,
            'Omeka\ApiManager' => $this->api,
            'Omeka\RdfImporter' => $this->rdfImporter,
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

    public function testInstallImportsTheBundledSkosVocabulary(): void
    {
        $this->module->install($this->services);

        $this->assertCount(1, $this->rdfImporter->imports);
        [$strategy, $vocab, $options] = $this->rdfImporter->imports[0];
        $this->assertSame('file', $strategy);
        $this->assertSame('http://www.w3.org/2004/02/skos/core#', $vocab['o:namespace_uri']);
        $this->assertSame('skos', $vocab['o:prefix']);
        $this->assertSame('SKOS', $vocab['o:label']);
        $this->assertSame('rdfxml', $options['format']);
        $this->assertFileExists($options['file']);
        $this->assertStringContainsString('http://www.w3.org/2004/02/skos/core#', file_get_contents($options['file']));
    }

    public function testInstallReusesAnInstalledSkosVocabulary(): void
    {
        $this->api->vocabularies[] = 'http://www.w3.org/2004/02/skos/core#';

        $this->module->install($this->services);

        $this->assertSame([], $this->rdfImporter->imports);
        $this->assertCount(4, $this->connection->statements);
    }

    public function testFilesPathIsInsideTheFileStore(): void
    {
        $this->assertSame(
            $this->filesPath . '/educationalcurriculum',
            Module::filesPath($this->services->get('Config'))
        );
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
