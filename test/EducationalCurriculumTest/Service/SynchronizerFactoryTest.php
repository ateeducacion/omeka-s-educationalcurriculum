<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Service;

use EducationalCurriculum\Service\SynchronizerFactory;
use EducationalCurriculum\Sync\Synchronizer;
use EducationalCurriculumTest\Support\FakeApi;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use stdClass;

class SynchronizerFactoryTest extends TestCase
{
    public function testBuildsTheSynchronizerFromOmekaServices(): void
    {
        $services = new ServiceManager(['services' => [
            'Omeka\ApiManager' => new FakeApi(),
            'Omeka\EntityManager' => new stdClass(),
            'Omeka\AuthenticationService' => new stdClass(),
            'Omeka\Connection' => new stdClass(),
            'Omeka\RdfImporter' => new stdClass(),
            'Omeka\Logger' => new stdClass(),
        ]]);

        $synchronizer = (new SynchronizerFactory())($services, Synchronizer::class);

        $this->assertInstanceOf(Synchronizer::class, $synchronizer);
    }

    public function testIsRegisteredAsAService(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';

        $this->assertSame(
            SynchronizerFactory::class,
            $config['service_manager']['factories'][Synchronizer::class]
        );
    }
}
