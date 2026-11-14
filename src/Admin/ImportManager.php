<?php
declare(strict_types=1);

namespace EducationalCurriculum\Admin;

use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Job\Analyze;
use EducationalCurriculum\Job\Apply;
use EducationalCurriculum\Module;
use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Release\ReleaseException;
use EducationalCurriculum\Sync\StructureWriter;
use EducationalCurriculum\Sync\Synchronizer;
use Omeka\Entity\User;
use SplFileObject;

/**
 * What the curriculum pages do: show the loaded curriculum and the imports, accept an upload,
 * start the analysis and the apply step, discard an import and read its report.
 */
class ImportManager
{
    const RUNNING = [CurriculumImport::STATUS_ANALYZING, CurriculumImport::STATUS_APPLYING];
    const ANALYZABLE = [
        CurriculumImport::STATUS_READY,
        CurriculumImport::STATUS_BLOCKED,
        CurriculumImport::STATUS_STALE,
        CurriculumImport::STATUS_INTERRUPTED,
    ];
    const KEPT = [
        CurriculumImport::STATUS_APPLIED,
        CurriculumImport::STATUS_ANALYZING,
        CurriculumImport::STATUS_APPLYING,
    ];
    const RELEASE_FILES = ['curriculo.json', 'curriculo.json.sha256', 'cur.ttl'];
    const BUSY = 'Another import is being analyzed or applied; wait until it finishes.'; // @translate

    private $entityManager;
    private $api;
    private $dispatcher;
    private $reader;
    private $structureWriter;
    private $config;

    /**
     * @param \Doctrine\ORM\EntityManager $entityManager
     * @param \Omeka\Api\Manager $api
     * @param \Omeka\Job\Dispatcher $dispatcher
     * @param array $config The application configuration
     */
    public function __construct(
        $entityManager,
        $api,
        $dispatcher,
        Reader $reader,
        StructureWriter $structureWriter,
        array $config
    ) {
        $this->entityManager = $entityManager;
        $this->api = $api;
        $this->dispatcher = $dispatcher;
        $this->reader = $reader;
        $this->structureWriter = $structureWriter;
        $this->config = $config;
    }

    /**
     * Loaded version, when it was applied, active and retired items, and the imports, newest first.
     */
    public function overview(): array
    {
        $sets = $this->structureWriter->findItemSets($this->uriBase());
        $imports = $this->repository()->findBy([], ['id' => 'DESC']);
        $appliedAt = null;
        foreach ($imports as $import) {
            if ($import->getStatus() === CurriculumImport::STATUS_APPLIED) {
                $appliedAt = $import->getModified() ?? $import->getCreated();
                break;
            }
        }
        $active = 0;
        if ($sets['curriculum'] !== null) {
            $active = $this->api->search('items', [
                'item_set_id' => $sets['curriculum'],
                'property' => [['property' => 'skos:changeNote', 'type' => 'nex']],
                'limit' => 0,
            ])->getTotalResults();
        }
        $retired = 0;
        if ($sets['retired'] !== null) {
            $retired = $this->api->search('items', ['item_set_id' => $sets['retired'], 'limit' => 0])
                ->getTotalResults();
        }
        return [
            'version' => $this->loadedVersion($sets['curriculum']),
            'appliedAt' => $appliedAt,
            'active' => $active,
            'retired' => $retired,
            'imports' => $imports,
        ];
    }

    /**
     * Check an uploaded release, store its files and start its analysis. Nothing is saved when a
     * check fails.
     *
     * @throws ReleaseException|ImportException
     */
    public function upload(
        string $jsonPath,
        string $checksumPath,
        string $vocabularyPath,
        User $owner
    ): CurriculumImport {
        $this->checkNothingRunning();
        $release = $this->reader->read($jsonPath, $checksumPath, $vocabularyPath);
        if ($release->getUriBase() !== $this->uriBase()) {
            throw new ReleaseException(
                'The release publishes its URIs under %s, not under %s.', // @translate
                $release->getUriBase(),
                $this->uriBase()
            );
        }
        $sets = $this->structureWriter->findItemSets($this->uriBase());
        Reader::checkNotOlder($release->getVersion(), $this->loadedVersion($sets['curriculum']));

        $import = new CurriculumImport();
        $import->setVersion($release->getVersion());
        $import->setStatus(CurriculumImport::STATUS_ANALYZING);
        $import->setOwner($owner);
        $this->entityManager->persist($import);
        $this->entityManager->flush();

        $directory = $this->directory($import);
        mkdir($directory, 0775, true);
        foreach (array_combine(self::RELEASE_FILES, [$jsonPath, $checksumPath, $vocabularyPath]) as $name => $path) {
            copy($path, $directory . '/' . $name);
        }
        $import->setAnalyzeJob($this->dispatcher->dispatch(Analyze::class, ['import_id' => $import->getId()]));
        $this->entityManager->flush();
        return $import;
    }

    /**
     * Analyze an import again, after errors, an interrupted apply step or changes in Omeka.
     */
    public function analyze(CurriculumImport $import): void
    {
        if (!in_array($import->getStatus(), self::ANALYZABLE, true)) {
            throw new ImportException('This import cannot be analyzed now.'); // @translate
        }
        $this->checkNothingRunning();
        $import->setStatus(CurriculumImport::STATUS_ANALYZING);
        $import->setAnalyzeJob($this->dispatcher->dispatch(Analyze::class, ['import_id' => $import->getId()]));
        $this->entityManager->flush();
    }

    /**
     * Start applying the approved report; the job marks the import as applying.
     */
    public function apply(CurriculumImport $import): void
    {
        if ($import->getStatus() !== CurriculumImport::STATUS_READY) {
            throw new ImportException('Only an analyzed import without errors can be applied.'); // @translate
        }
        $job = $import->getApplyJob();
        if ($job !== null && in_array($job->getStatus(), ['starting', 'in_progress'], true)) {
            throw new ImportException(self::BUSY);
        }
        $this->checkNothingRunning();
        $import->setApplyJob($this->dispatcher->dispatch(Apply::class, ['import_id' => $import->getId()]));
        $this->entityManager->flush();
    }

    /**
     * Delete an import that was not applied, with its files.
     */
    public function discard(CurriculumImport $import): void
    {
        if (in_array($import->getStatus(), self::KEPT, true)) {
            throw new ImportException('Applied imports and imports in progress cannot be discarded.'); // @translate
        }
        $directory = $this->directory($import);
        if (is_dir($directory) && !is_link($directory)) {
            foreach (glob($directory . '/*') as $file) {
                if (is_file($file) || is_link($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
        $this->entityManager->remove($import);
        $this->entityManager->flush();
    }

    /**
     * The summary of the report (resumen.json), or null before the analysis.
     */
    public function summary(CurriculumImport $import): ?array
    {
        $path = $this->directory($import) . '/' . Synchronizer::SUMMARY;
        return is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    }

    /**
     * One page of a list of the report (new, modified or retired), reading only its lines.
     */
    public function page(CurriculumImport $import, string $list, int $page, int $perPage): array
    {
        if (!isset(Synchronizer::LISTS[$list])) {
            throw new ImportException('Unknown list "%s".', $list);
        }
        $path = $this->directory($import) . '/' . Synchronizer::LISTS[$list];
        if (!is_file($path)) {
            return [];
        }
        $file = new SplFileObject($path);
        $file->seek(max(0, ($page - 1) * $perPage));
        $entries = [];
        while (count($entries) < $perPage && !$file->eof()) {
            $line = trim((string) $file->current());
            if ($line !== '') {
                $entries[] = json_decode($line, true);
            }
            $file->next();
        }
        return $entries;
    }

    public function find(int $id): ?CurriculumImport
    {
        return $this->entityManager->find(CurriculumImport::class, $id);
    }

    private function checkNothingRunning(): void
    {
        if ($this->repository()->findBy(['status' => self::RUNNING])) {
            throw new ImportException(self::BUSY);
        }
    }

    private function loadedVersion(?int $itemSetId): ?string
    {
        if ($itemSetId === null) {
            return null;
        }
        $version = $this->api->read('item_sets', $itemSetId)->getContent()->value('dcterms:hasVersion');
        return $version === null ? null : (string) $version;
    }

    private function directory(CurriculumImport $import): string
    {
        return Module::filesPath($this->config) . '/' . $import->getId();
    }

    private function uriBase(): string
    {
        return $this->config['educationalcurriculum']['uri_base'];
    }

    private function repository()
    {
        return $this->entityManager->getRepository(CurriculumImport::class);
    }
}
