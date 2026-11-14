<?php
declare(strict_types=1);

namespace EducationalCurriculum\Job;

use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Sync\Synchronizer;
use Throwable;

/**
 * Plans an uploaded release and saves the report a person approves before applying it.
 */
class Analyze extends AbstractImportJob
{
    public function perform()
    {
        $import = $this->import();
        try {
            $plan = $this->getServiceLocator()->get(Synchronizer::class)->analyze($this->directory());
        } catch (Throwable $e) {
            $this->setStatus(CurriculumImport::STATUS_STALE);
            throw $e;
        }
        $import->setReportHash($plan->getFingerprint());
        $this->setStatus($plan->hasErrors() ? CurriculumImport::STATUS_BLOCKED : CurriculumImport::STATUS_READY);
    }
}
