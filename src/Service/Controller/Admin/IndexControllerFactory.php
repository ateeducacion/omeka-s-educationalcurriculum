<?php
declare(strict_types=1);

namespace EducationalCurriculum\Service\Controller\Admin;

use EducationalCurriculum\Admin\ImportManager;
use EducationalCurriculum\Controller\Admin\IndexController;

class IndexControllerFactory
{
    /**
     * @param \Psr\Container\ContainerInterface $services
     */
    public function __invoke($services, $requestedName, ?array $options = null): IndexController
    {
        return new IndexController($services->get(ImportManager::class));
    }
}
