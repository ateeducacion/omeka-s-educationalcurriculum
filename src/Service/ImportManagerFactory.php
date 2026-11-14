<?php
declare(strict_types=1);

namespace EducationalCurriculum\Service;

use EducationalCurriculum\Admin\ImportManager;
use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Sync\StructureWriter;

class ImportManagerFactory
{
    /**
     * @param \Psr\Container\ContainerInterface $services
     */
    public function __invoke($services, $requestedName, ?array $options = null): ImportManager
    {
        $api = $services->get('Omeka\ApiManager');
        return new ImportManager(
            $services->get('Omeka\EntityManager'),
            $api,
            $services->get('Omeka\Job\Dispatcher'),
            new Reader(),
            new StructureWriter($api, $services->get('Omeka\RdfImporter')),
            $services->get('Config')
        );
    }
}
