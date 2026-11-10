<?php
declare(strict_types=1);

namespace EducationalCurriculum;

use Omeka\Module\AbstractModule;

/**
 * Loads published releases of the LOMLOE Canary Islands curriculum into Omeka S.
 */
class Module extends AbstractModule
{
    /**
     * Retrieve the configuration array.
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
