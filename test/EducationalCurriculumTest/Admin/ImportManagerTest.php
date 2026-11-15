<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Admin;

use EducationalCurriculum\Admin\ImportException;
use EducationalCurriculum\Admin\ImportManager;
use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Job\Analyze;
use EducationalCurriculum\Job\Apply;
use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Release\ReleaseException;
use EducationalCurriculum\Sync\StructureWriter;
use EducationalCurriculumTest\Support\FakeApi;
use EducationalCurriculumTest\Support\FakeDispatcher;
use EducationalCurriculumTest\Support\FakeEntityManager;
use EducationalCurriculumTest\Support\FakeResource;
use Omeka\Entity\Job;
use Omeka\Entity\User;
use PHPUnit\Framework\TestCase;

class ImportManagerTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/release-1.1.2';
    private const BASE = 'https://ateeducacion.github.io/curriculo_lomloe_canarias/';

    private $files;
    private $upload;
    private $entityManager;
    private $api;
    private $dispatcher;

    protected function setUp(): void
    {
        $this->files = sys_get_temp_dir() . '/educationalcurriculum-manager-' . uniqid();
        $this->upload = $this->files . '/upload';
        mkdir($this->upload, 0777, true);
        foreach (['curriculo.json', 'curriculo.json.sha256', 'cur.ttl'] as $file) {
            copy(self::FIXTURE . '/' . $file, $this->upload . '/' . $file);
        }
        $this->entityManager = new FakeEntityManager();
        $this->api = new FakeApi();
        $this->dispatcher = new FakeDispatcher();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->files));
    }

    public function testOverviewOfAnInstanceWithoutCurriculum(): void
    {
        $this->assertSame([
            'version' => null,
            'appliedAt' => null,
            'active' => 0,
            'retired' => 0,
            'imports' => [],
        ], $this->manager()->overview());
    }

    public function testOverviewOfALoadedCurriculum(): void
    {
        $this->loadedVersion('1.1.2');
        $this->api->willCount('items', ['item_set_id' => 80, 'property' => [
            ['property' => 'skos:changeNote', 'type' => 'nex'],
        ], 'limit' => 0], 15400);
        $this->api->willCount('items', ['item_set_id' => 81, 'limit' => 0], 38);
        $old = $this->import(1, CurriculumImport::STATUS_APPLIED);
        $applied = $this->import(2, CurriculumImport::STATUS_APPLIED);
        $applied->preUpdate();
        $discarded = $this->import(3, CurriculumImport::STATUS_BLOCKED);

        $overview = $this->manager()->overview();

        $this->assertSame('1.1.2', $overview['version']);
        $this->assertSame($applied->getModified(), $overview['appliedAt']);
        $this->assertSame(15400, $overview['active']);
        $this->assertSame(38, $overview['retired']);
        $this->assertSame([$discarded, $applied, $old], $overview['imports']);
    }

    public function testUploadStoresTheFilesAndStartsTheAnalysis(): void
    {
        $owner = new User();

        $import = $this->manager()->upload(...$this->uploadedFiles($owner));

        $this->assertSame(1, $import->getId());
        $this->assertSame('1.1.2', $import->getVersion());
        $this->assertSame(CurriculumImport::STATUS_ANALYZING, $import->getStatus());
        $this->assertSame($owner, $import->getOwner());
        foreach (['curriculo.json', 'curriculo.json.sha256', 'cur.ttl'] as $file) {
            $this->assertFileEquals(self::FIXTURE . "/$file", $this->files . "/educationalcurriculum/1/$file");
        }
        $this->assertSame([[Analyze::class, ['import_id' => 1]]], $this->dispatcher->dispatched);
        $this->assertInstanceOf(Job::class, $import->getAnalyzeJob());
        $this->assertSame([$import], $this->entityManager->getRepository(CurriculumImport::class)->findBy([]));
    }

    /**
     * With Omeka's synchronous strategy the job runs inside dispatch() and the entity manager is
     * cleared afterwards: the import is read again and the job is attached by reference.
     */
    public function testUploadWorksWhenTheJobRunsInsideTheDispatch(): void
    {
        $this->synchronousJobs(CurriculumImport::STATUS_READY);

        $import = $this->manager()->upload(...$this->uploadedFiles());

        $stored = $this->entityManager->find(CurriculumImport::class, 1);
        $this->assertSame($stored, $import);
        $this->assertSame(CurriculumImport::STATUS_READY, $stored->getStatus());
        $this->assertSame(1, $stored->getAnalyzeJob()->getId());
    }

    public function testAnalysisAgainKeepsTheStatusLeftByASynchronousJob(): void
    {
        $this->import(1, CurriculumImport::STATUS_STALE);
        $this->synchronousJobs(CurriculumImport::STATUS_BLOCKED, CurriculumImport::STATUS_ANALYZING);

        $this->manager()->analyze($this->entityManager->find(CurriculumImport::class, 1));

        $stored = $this->entityManager->find(CurriculumImport::class, 1);
        $this->assertSame(CurriculumImport::STATUS_BLOCKED, $stored->getStatus());
        $this->assertSame(1, $stored->getAnalyzeJob()->getId());
    }

    public function testApplyRecordsItsJobWhenItRunsInsideTheDispatch(): void
    {
        $this->import(1, CurriculumImport::STATUS_READY);
        $this->synchronousJobs(CurriculumImport::STATUS_APPLIED);

        $this->manager()->apply($this->entityManager->find(CurriculumImport::class, 1));

        $stored = $this->entityManager->find(CurriculumImport::class, 1);
        $this->assertSame(CurriculumImport::STATUS_APPLIED, $stored->getStatus());
        $this->assertSame(1, $stored->getApplyJob()->getId());
    }

    public function testUploadRejectsAnInvalidReleaseWithoutSavingAnything(): void
    {
        file_put_contents($this->upload . '/curriculo.json.sha256', str_repeat('0', 64) . "  curriculo.json\n");

        try {
            $this->manager()->upload(...$this->uploadedFiles());
            $this->fail('The upload should be rejected.');
        } catch (ReleaseException $e) {
            $this->assertSame('The sha256 checksum does not match curriculo.json.', $e->getMessage());
        }
        $this->assertSame([], $this->entityManager->getRepository(CurriculumImport::class)->findBy([]));
        $this->assertDirectoryDoesNotExist($this->files . '/educationalcurriculum');
        $this->assertSame([], $this->dispatcher->dispatched);
    }

    public function testUploadRejectsAVersionOlderThanTheLoadedOne(): void
    {
        $this->loadedVersion('1.2.0');

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('Version 1.1.2 is older than the loaded version 1.2.0.');

        $this->manager()->upload(...$this->uploadedFiles());
    }

    public function testUploadRejectsAReleaseForAnotherUriBase(): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage(
            'The release publishes its URIs under ' . self::BASE . ', not under https://example.org/curriculum/.'
        );

        $this->manager('https://example.org/curriculum/')->upload(...$this->uploadedFiles());
    }

    /**
     * @dataProvider runningStatuses
     */
    public function testUploadWaitsForAnImportInProgress(string $status): void
    {
        $this->import(1, $status);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Another import is being analyzed or applied; wait until it finishes.');

        $this->manager()->upload(...$this->uploadedFiles());
    }

    public function runningStatuses(): array
    {
        return [[CurriculumImport::STATUS_ANALYZING], [CurriculumImport::STATUS_APPLYING]];
    }

    public function testAnalyzesAnImportAgain(): void
    {
        $import = $this->import(1, CurriculumImport::STATUS_INTERRUPTED);

        $this->manager()->analyze($import);

        $this->assertSame(CurriculumImport::STATUS_ANALYZING, $import->getStatus());
        $this->assertSame([[Analyze::class, ['import_id' => 1]]], $this->dispatcher->dispatched);
        $this->assertSame(1, $import->getAnalyzeJob()->getId());
    }

    public function testDoesNotAnalyzeAnAppliedImport(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('This import cannot be analyzed now.');

        $this->manager()->analyze($this->import(1, CurriculumImport::STATUS_APPLIED));
    }

    public function testAppliesAReadyImport(): void
    {
        $import = $this->import(1, CurriculumImport::STATUS_READY);

        $this->manager()->apply($import);

        $this->assertSame([[Apply::class, ['import_id' => 1]]], $this->dispatcher->dispatched);
        $this->assertSame(1, $import->getApplyJob()->getId());
        $this->assertSame(CurriculumImport::STATUS_READY, $import->getStatus());
    }

    public function testDoesNotApplyAnImportThatIsNotReady(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Only an analyzed import without errors can be applied.');

        $this->manager()->apply($this->import(1, CurriculumImport::STATUS_BLOCKED));
    }

    public function testDoesNotApplyTwice(): void
    {
        $import = $this->import(1, CurriculumImport::STATUS_READY);
        $job = new Job();
        $job->setStatus('starting');
        $import->setApplyJob($job);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Another import is being analyzed or applied; wait until it finishes.');

        $this->manager()->apply($import);
    }

    public function testDiscardsAnImportWithItsFiles(): void
    {
        $import = $this->import(1, CurriculumImport::STATUS_BLOCKED);
        mkdir($this->files . '/educationalcurriculum/1', 0777, true);
        touch($this->files . '/educationalcurriculum/1/curriculo.json');

        $this->manager()->discard($import);

        $this->assertDirectoryDoesNotExist($this->files . '/educationalcurriculum/1');
        $this->assertNull($this->entityManager->find(CurriculumImport::class, 1));
    }

    /**
     * @dataProvider keptStatuses
     */
    public function testKeepsAppliedAndRunningImports(string $status): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Applied imports and imports in progress cannot be discarded.');

        $this->manager()->discard($this->import(1, $status));
    }

    public function keptStatuses(): array
    {
        return [[CurriculumImport::STATUS_APPLIED], [CurriculumImport::STATUS_ANALYZING],
            [CurriculumImport::STATUS_APPLYING]];
    }

    public function testReadsTheSummaryOfTheReport(): void
    {
        $import = $this->import(1, CurriculumImport::STATUS_READY);
        $this->assertNull($this->manager()->summary($import));

        mkdir($this->files . '/educationalcurriculum/1', 0777, true);
        file_put_contents($this->files . '/educationalcurriculum/1/resumen.json', '{"empty":true}');

        $this->assertSame(['empty' => true], $this->manager()->summary($import));
    }

    public function testReadsOnePageOfAList(): void
    {
        $import = $this->import(1, CurriculumImport::STATUS_READY);
        mkdir($this->files . '/educationalcurriculum/1', 0777, true);
        $lines = '';
        foreach (range(1, 5) as $n) {
            $lines .= json_encode(['identifier' => "sb:$n"]) . "\n";
        }
        file_put_contents($this->files . '/educationalcurriculum/1/nuevos.jsonl', $lines);

        $this->assertSame(
            [['identifier' => 'sb:3'], ['identifier' => 'sb:4']],
            $this->manager()->page($import, 'new', 2, 2)
        );
        $this->assertSame([['identifier' => 'sb:5']], $this->manager()->page($import, 'new', 3, 2));
        $this->assertSame([], $this->manager()->page($import, 'new', 4, 2));
        $this->assertSame([], $this->manager()->page($import, 'retired', 1, 2));
    }

    public function testRejectsAnUnknownList(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Unknown list "other".');

        $this->manager()->page($this->import(1, CurriculumImport::STATUS_READY), 'other', 1, 50);
    }

    /**
     * Jobs run inside dispatch(): each one checks the saved status, leaves its own and the entity
     * manager is cleared, as Omeka\Job\DispatchStrategy\Synchronous does.
     */
    private function synchronousJobs(string $resultStatus, ?string $expectedStatus = null): void
    {
        $this->dispatcher = new FakeDispatcher(function ($class, $args) use ($resultStatus, $expectedStatus) {
            $import = $this->entityManager->find(CurriculumImport::class, $args['import_id']);
            if ($expectedStatus !== null) {
                $this->assertSame($expectedStatus, $import->getStatus(), 'The status is saved before the job runs.');
            }
            $import->setStatus($resultStatus);
            $this->entityManager->flush();
            $this->entityManager->clear();
        });
    }

    private function manager(string $uriBase = self::BASE): ImportManager
    {
        return new ImportManager(
            $this->entityManager,
            $this->api,
            $this->dispatcher,
            new Reader(),
            new StructureWriter($this->api, null),
            [
                'file_store' => ['local' => ['base_path' => $this->files]],
                'educationalcurriculum' => ['uri_base' => $uriBase],
            ]
        );
    }

    private function uploadedFiles(?User $owner = null): array
    {
        return [
            $this->upload . '/curriculo.json',
            $this->upload . '/curriculo.json.sha256',
            $this->upload . '/cur.ttl',
            $owner ?? new User(),
        ];
    }

    private function loadedVersion(string $version): void
    {
        $this->api->willFind('item_sets', ['property' => [
            ['property' => 'dcterms:identifier', 'type' => 'eq', 'text' => self::BASE . 'id/curriculo'],
        ]], [new FakeResource(80)]);
        $this->api->willFind('item_sets', ['property' => [
            ['property' => 'dcterms:identifier', 'type' => 'eq', 'text' => self::BASE . 'id/curriculo/retirados'],
        ]], [new FakeResource(81)]);
        $this->api->willRead('item_sets', 80, new FakeResource(80, '', ['dcterms:hasVersion' => $version]));
    }

    private function import(int $id, string $status): CurriculumImport
    {
        $import = new CurriculumImport();
        $import->setVersion('1.1.2');
        $import->setStatus($status);
        $import->prePersist();
        return $this->entityManager->add($import, $id);
    }
}
