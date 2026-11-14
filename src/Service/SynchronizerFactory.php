<?php
declare(strict_types=1);

namespace EducationalCurriculum\Service;

use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Sync\CurrentState;
use EducationalCurriculum\Sync\Planner;
use EducationalCurriculum\Sync\StructureWriter;
use EducationalCurriculum\Sync\Synchronizer;
use EducationalCurriculum\Sync\Writer;

class SynchronizerFactory
{
    /**
     * @param \Psr\Container\ContainerInterface $services
     */
    public function __invoke($services, $requestedName, ?array $options = null): Synchronizer
    {
        $api = $services->get('Omeka\ApiManager');
        $rdfImporter = $services->get('Omeka\RdfImporter');
        $logger = $services->get('Omeka\Logger');
        return new Synchronizer(
            new Reader(),
            new CurrentState($services->get('Omeka\Connection')),
            new StructureWriter($api, $rdfImporter),
            new Writer(
                $api,
                $services->get('Omeka\EntityManager'),
                $services->get('Omeka\AuthenticationService'),
                function (string $message) use ($logger) {
                    $logger->info($message);
                }
            ),
            $rdfImporter,
            new Planner()
        );
    }
}
