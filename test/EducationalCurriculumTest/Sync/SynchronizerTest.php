<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Sync;

use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Sync\CurrentItem;
use EducationalCurriculum\Sync\CurrentState;
use EducationalCurriculum\Sync\Plan;
use EducationalCurriculum\Sync\Planner;
use EducationalCurriculum\Sync\Structure;
use EducationalCurriculum\Sync\StructureWriter;
use EducationalCurriculum\Sync\Synchronizer;
use EducationalCurriculum\Sync\Writer;
use PHPUnit\Framework\TestCase;

class SynchronizerTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/release-1.1.2';
    private const NAMESPACE = 'https://ateeducacion.github.io/curriculo_lomloe_canarias/def/cur#';
    private const CLASSES = ['Etapa', 'Curso', 'Materia', 'MateriaCurso', 'CompetenciaEspecifica',
        'BloqueCompetencial', 'CriterioEvaluacion', 'BloqueSaberes', 'SaberBasico'];
    private const PROPERTIES = ['enunciadoEnCurso', 'agrupa', 'bloqueCompetencial', 'descriptorOperativo',
        'etapa', 'curso', 'materia', 'materiaCurso'];

    private $dir;
    private $state;
    private $structureWriter;
    private $writer;
    private $rdfImporter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/educationalcurriculum-sync-' . uniqid();
        mkdir($this->dir);
        foreach (['curriculo.json', 'curriculo.json.sha256', 'cur.ttl'] as $file) {
            copy(self::FIXTURE . '/' . $file, $this->dir . '/' . $file);
        }
        $this->state = $this->createMock(CurrentState::class);
        $this->state->method('vocabularyTerms')->willReturn([]);
        $this->state->method('read')->willReturn([]);
        $this->structureWriter = $this->createMock(StructureWriter::class);
        $this->structureWriter->method('findItemSets')->willReturn(['curriculum' => null, 'retired' => null]);
        $this->writer = $this->createMock(Writer::class);
        $this->rdfImporter = new class (self::CLASSES, self::PROPERTIES) {
            public $calls = [];
            private $classes;
            private $properties;

            public function __construct(array $classes, array $properties)
            {
                $this->classes = $classes;
                $this->properties = $properties;
            }

            public function getMembers($strategy, $namespaceUri, array $options = [])
            {
                $this->calls[] = [$strategy, $namespaceUri, $options];
                return [
                    'classes' => array_fill_keys($this->classes, ['label' => '', 'comment' => '']),
                    'properties' => array_fill_keys($this->properties, ['label' => '', 'comment' => '']),
                ];
            }
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testAnalysisSavesTheReportAndItsSummary(): void
    {
        $plan = $this->synchronizer()->analyze($this->dir);

        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertCount(125, $plan->getNew());
        $this->assertFalse($plan->hasErrors());
        $this->assertSame(['added' => array_merge(
            array_map(function ($name) {
                return 'cur:' . $name;
            }, self::CLASSES),
            array_map(function ($name) {
                return 'cur:' . $name;
            }, self::PROPERTIES)
        ), 'removed' => []], $plan->getVocabulary());
        $this->assertSame($plan->toJson(), file_get_contents($this->dir . '/informe.json'));
        $summary = json_decode(file_get_contents($this->dir . '/resumen.json'), true);
        $this->assertSame([
            'version' => '1.1.2',
            'fingerprint' => $plan->getFingerprint(),
            'empty' => false,
            'counts' => ['new' => 125, 'modified' => 0, 'retired' => 0],
            'summary' => $plan->getSummary(),
            'vocabulary' => $plan->getVocabulary(),
            'errors' => [],
        ], $summary);
        $this->assertSame(
            [['file', self::NAMESPACE, ['file' => $this->dir . '/cur.ttl', 'format' => 'turtle']]],
            $this->rdfImporter->calls
        );
    }

    public function testAnalysisSavesEachListOneEntryPerLine(): void
    {
        $plan = $this->synchronizer()->analyze($this->dir);

        $lines = file($this->dir . '/nuevos.jsonl', FILE_IGNORE_NEW_LINES);
        $this->assertCount(125, $lines);
        $this->assertSame($plan->getNew()[0], json_decode($lines[0], true));
        $this->assertSame('', file_get_contents($this->dir . '/modificados.jsonl'));
        $this->assertSame('', file_get_contents($this->dir . '/retirados.jsonl'));
    }

    public function testAnalysisComparesWithTheItemsOfTheCurriculumItemSet(): void
    {
        $this->structureWriter = $this->createMock(StructureWriter::class);
        $this->structureWriter->method('findItemSets')->willReturn(['curriculum' => 80, 'retired' => 81]);
        $this->state = $this->createMock(CurrentState::class);
        $this->state->method('vocabularyTerms')->willReturn([]);
        $this->state->expects($this->once())->method('read')->with(80, 81)->willReturn([
            new CurrentItem(9, 'cur:Etapa', ['dcterms:identifier' => [
                ['type' => 'literal', '@value' => 'etapa:XXX', '@language' => 'es'],
            ]], false),
        ]);

        $plan = $this->synchronizer()->analyze($this->dir);

        $this->assertSame(Plan::ERROR_UNKNOWN_IDENTIFIER, $plan->getErrors()[0]['code']);
        $summary = json_decode(file_get_contents($this->dir . '/resumen.json'), true);
        $this->assertSame($plan->getErrors(), $summary['errors']);
    }

    public function testApplyWritesTheApprovedPlanAndRecordsTheVersionLast(): void
    {
        $fingerprint = $this->synchronizer()->analyze($this->dir)->getFingerprint();
        $structure = new Structure([], [], [], 80, 81);
        $calls = [];
        $this->structureWriter->expects($this->once())->method('ensureVocabulary')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'vocabulary';
            });
        $this->structureWriter->expects($this->once())->method('prepare')
            ->willReturnCallback(function ($release, array $classProperties, array $extra) use (&$calls, $structure) {
                $calls[] = 'structure';
                $this->assertSame(
                    ['dcterms:identifier', 'skos:notation', 'skos:prefLabel'],
                    $classProperties['cur:Etapa']
                );
                $this->assertSame([
                    'dcterms:identifier', 'skos:notation', 'skos:prefLabel', 'skos:definition', 'skos:broader',
                    'cur:bloqueCompetencial', 'cur:descriptorOperativo', 'cur:etapa', 'cur:curso', 'cur:materia',
                    'cur:materiaCurso',
                ], $classProperties['cur:CriterioEvaluacion']);
                $this->assertCount(9, $classProperties);
                $this->assertSame([], $extra);
                return $structure;
            });
        $this->writer->expects($this->once())->method('apply')
            ->willReturnCallback(function (Plan $plan, Structure $given, array $itemIds) use (&$calls, $structure) {
                $calls[] = 'items';
                $this->assertCount(125, $plan->getNew());
                $this->assertSame($structure, $given);
                $this->assertSame([], $itemIds);
                return [];
            });
        $this->structureWriter->expects($this->once())->method('setVersion')
            ->willReturnCallback(function (Structure $given, string $version) use (&$calls) {
                $calls[] = 'version ' . $version;
            });

        $this->assertTrue($this->synchronizer()->apply($this->dir, $fingerprint));
        $this->assertSame(['vocabulary', 'structure', 'items', 'version 1.1.2'], $calls);
    }

    public function testApplyPassesTheIdsOfTheExistingItems(): void
    {
        $this->structureWriter = $this->createMock(StructureWriter::class);
        $this->structureWriter->method('findItemSets')->willReturn(['curriculum' => 80, 'retired' => 81]);
        $this->structureWriter->method('prepare')->willReturn(new Structure([], [], [], 80, 81));
        $this->state = $this->createMock(CurrentState::class);
        $this->state->method('vocabularyTerms')->willReturn([]);
        $this->state->method('read')->willReturn([new CurrentItem(9, 'cur:Etapa', ['dcterms:identifier' => [
            ['type' => 'literal', '@value' => 'etapa:ESO', '@language' => 'es'],
        ]], false)]);
        $fingerprint = $this->synchronizer()->analyze($this->dir)->getFingerprint();
        $this->writer->expects($this->once())->method('apply')
            ->with($this->anything(), $this->anything(), ['etapa:ESO' => 9])
            ->willReturn([]);

        $this->assertTrue($this->synchronizer()->apply($this->dir, $fingerprint));
    }

    public function testApplyWritesNothingWhenThePlanNoLongerMatchesTheReport(): void
    {
        $this->structureWriter->expects($this->never())->method('ensureVocabulary');
        $this->structureWriter->expects($this->never())->method('prepare');
        $this->writer->expects($this->never())->method('apply');
        $this->structureWriter->expects($this->never())->method('setVersion');

        $this->assertFalse($this->synchronizer()->apply($this->dir, str_repeat('0', 64)));
    }

    public function testApplyWritesNothingWhenThePlanHasErrors(): void
    {
        $this->rdfImporter = new class {
            public function getMembers($strategy, $namespaceUri, array $options = [])
            {
                return ['classes' => [], 'properties' => []];
            }
        };
        $plan = $this->synchronizer()->analyze($this->dir);
        $this->assertTrue($plan->hasErrors());
        $this->writer->expects($this->never())->method('apply');

        $this->assertFalse($this->synchronizer()->apply($this->dir, $plan->getFingerprint()));
    }

    private function synchronizer(): Synchronizer
    {
        return new Synchronizer(
            new Reader(),
            $this->state,
            $this->structureWriter,
            $this->writer,
            $this->rdfImporter,
            new Planner()
        );
    }
}
