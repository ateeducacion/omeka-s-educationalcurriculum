<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Job;

use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Job\Analyze;
use EducationalCurriculum\Job\Apply;
use EducationalCurriculum\Sync\Plan;
use EducationalCurriculum\Sync\Synchronizer;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Entity\Job;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

class JobsTest extends TestCase
{
    private $import;
    private $entityManager;
    private $synchronizer;
    private $logger;

    protected function setUp(): void
    {
        $this->import = new CurriculumImport();
        $id = new ReflectionProperty(CurriculumImport::class, 'id');
        if (PHP_VERSION_ID < 80100) {
            $id->setAccessible(true);
        }
        $id->setValue($this->import, 7);
        $this->import->setVersion('1.1.2');
        $this->entityManager = new class ($this->import) {
            public $flushes = [];
            private $import;

            public function __construct($import)
            {
                $this->import = $import;
            }

            public function find($class, $id)
            {
                return $class === CurriculumImport::class && $id === 7 ? $this->import : null;
            }

            public function flush()
            {
                $this->flushes[] = $this->import->getStatus();
            }
        };
        $this->synchronizer = $this->createMock(Synchronizer::class);
        $this->logger = new class {
            public $messages = [];

            public function info($message)
            {
                $this->messages[] = $message;
            }

            public function warn($message)
            {
                $this->messages[] = $message;
            }
        };
    }

    public function testAnalysisMarksAnImportReadyWithTheFingerprintOfItsReport(): void
    {
        $plan = $this->plan([]);
        $this->synchronizer->expects($this->once())->method('analyze')
            ->with('/files/educationalcurriculum/7')->willReturn($plan);

        $this->job(Analyze::class)->perform();

        $this->assertSame(CurriculumImport::STATUS_READY, $this->import->getStatus());
        $this->assertSame($plan->getFingerprint(), $this->import->getReportHash());
        $this->assertSame(['ready'], $this->entityManager->flushes);
    }

    public function testAnalysisBlocksAnImportWithErrors(): void
    {
        $this->synchronizer->method('analyze')->willReturn($this->plan([
            ['code' => Plan::ERROR_UNKNOWN_IDENTIFIER, 'identifier' => 'etapa:X', 'item' => 9, 'detail' => []],
        ]));

        $this->job(Analyze::class)->perform();

        $this->assertSame(CurriculumImport::STATUS_BLOCKED, $this->import->getStatus());
    }

    public function testAFailedAnalysisLeavesTheImportNeedingAnalysis(): void
    {
        $this->import->setStatus(CurriculumImport::STATUS_ANALYZING);
        $this->synchronizer->method('analyze')->willThrowException(new RuntimeException('Broken'));

        try {
            $this->job(Analyze::class)->perform();
            $this->fail('The job should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Broken', $e->getMessage());
        }
        $this->assertSame(CurriculumImport::STATUS_STALE, $this->import->getStatus());
        $this->assertSame(['stale'], $this->entityManager->flushes);
    }

    public function testApplyWritesTheApprovedReport(): void
    {
        $this->import->setStatus(CurriculumImport::STATUS_READY);
        $this->import->setReportHash(str_repeat('a', 64));
        $this->synchronizer->expects($this->once())->method('apply')
            ->with('/files/educationalcurriculum/7', str_repeat('a', 64))->willReturn(true);

        $this->job(Apply::class)->perform();

        $this->assertSame(['applying', 'applied'], $this->entityManager->flushes);
        $this->assertSame(['Release 1.1.2 applied.'], $this->logger->messages);
    }

    public function testApplyStopsWhenTheReportIsOutdated(): void
    {
        $this->import->setStatus(CurriculumImport::STATUS_READY);
        $this->import->setReportHash(str_repeat('a', 64));
        $this->synchronizer->method('apply')->willReturn(false);

        $this->job(Apply::class)->perform();

        $this->assertSame(['applying', 'stale'], $this->entityManager->flushes);
        $this->assertSame(
            ['The items or the vocabulary changed after the analysis; nothing was written. Analyze the import again.'],
            $this->logger->messages
        );
    }

    public function testAnInterruptedApplyCanBeAnalyzedAgain(): void
    {
        $this->import->setStatus(CurriculumImport::STATUS_READY);
        $this->import->setReportHash(str_repeat('a', 64));
        $this->synchronizer->method('apply')->willThrowException(new RuntimeException('Lost connection'));

        try {
            $this->job(Apply::class)->perform();
            $this->fail('The job should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Lost connection', $e->getMessage());
        }
        $this->assertSame(['applying', 'interrupted'], $this->entityManager->flushes);
    }

    public function testApplyRefusesAnImportThatIsNotReady(): void
    {
        $this->import->setStatus(CurriculumImport::STATUS_BLOCKED);
        $this->synchronizer->expects($this->never())->method('apply');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Import 7 is blocked, not ready to apply.');

        $this->job(Apply::class)->perform();
    }

    public function testJobsFailForAMissingImport(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Curriculum import 99 not found.');

        $this->job(Analyze::class, 99)->perform();
    }

    private function job(string $class, int $importId = 7)
    {
        $job = new Job();
        $job->setArgs(['import_id' => $importId]);
        $services = new ServiceManager(['services' => [
            'Omeka\EntityManager' => $this->entityManager,
            'Omeka\Logger' => $this->logger,
            'Config' => ['file_store' => ['local' => ['base_path' => '/files']]],
            Synchronizer::class => $this->synchronizer,
        ]]);
        return new $class($job, $services);
    }

    private function plan(array $errors): Plan
    {
        return new Plan('1.1.2', [], [], [], [], ['added' => [], 'removed' => []], $errors);
    }
}
