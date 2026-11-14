<?php
declare(strict_types=1);

namespace EducationalCurriculum\Job;

use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Module;
use Omeka\Job\AbstractJob;
use RuntimeException;

/**
 * A job on one curriculum import, given as the import_id argument.
 */
abstract class AbstractImportJob extends AbstractJob
{
    protected function importId(): int
    {
        return (int) $this->getArg('import_id');
    }

    /**
     * Load the import; call it again after writing items, which clears the entity manager.
     */
    protected function import(): CurriculumImport
    {
        $import = $this->getServiceLocator()->get('Omeka\EntityManager')
            ->find(CurriculumImport::class, $this->importId());
        if ($import === null) {
            throw new RuntimeException(sprintf('Curriculum import %d not found.', $this->importId()));
        }
        return $import;
    }

    protected function setStatus(string $status): void
    {
        $this->import()->setStatus($status);
        $this->getServiceLocator()->get('Omeka\EntityManager')->flush();
    }

    /**
     * Directory with the uploaded files and the report of the import.
     */
    protected function directory(): string
    {
        return Module::filesPath($this->getServiceLocator()->get('Config')) . '/' . $this->importId();
    }
}
