<?php
declare(strict_types=1);

namespace EducationalCurriculum\Job;

use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Sync\Synchronizer;
use RuntimeException;
use Throwable;

/**
 * Applies the approved report of an import, unless the items changed since the analysis.
 */
class Apply extends AbstractImportJob
{
    public function perform()
    {
        $import = $this->import();
        if ($import->getStatus() !== CurriculumImport::STATUS_READY) {
            throw new RuntimeException(sprintf(
                'Import %d is %s, not ready to apply.',
                $this->importId(),
                $import->getStatus()
            ));
        }
        $version = $import->getVersion();
        $fingerprint = (string) $import->getReportHash();
        $this->setStatus(CurriculumImport::STATUS_APPLYING);

        try {
            $applied = $this->getServiceLocator()->get(Synchronizer::class)->apply($this->directory(), $fingerprint);
        } catch (Throwable $e) {
            $this->setStatus(CurriculumImport::STATUS_INTERRUPTED);
            throw $e;
        }

        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        if ($applied) {
            $this->setStatus(CurriculumImport::STATUS_APPLIED);
            $logger->info(sprintf('Release %s applied.', $version));
            return;
        }
        $this->setStatus(CurriculumImport::STATUS_STALE);
        $logger->warn(
            'The items or the vocabulary changed after the analysis; nothing was written. Analyze the import again.'
        );
    }
}
