<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Service;

use EducationalCurriculum\Admin\ImportManager;
use EducationalCurriculum\Controller\Admin\IndexController;
use EducationalCurriculum\Form\UploadForm;
use EducationalCurriculum\Service\Controller\Admin\IndexControllerFactory;
use EducationalCurriculum\Service\ImportManagerFactory;
use EducationalCurriculumTest\Support\FakeApi;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;
use stdClass;

class AdminFactoriesTest extends TestCase
{
    public function testBuildsTheImportManagerAndTheController(): void
    {
        $services = new ServiceManager(['services' => [
            'Omeka\ApiManager' => new FakeApi(),
            'Omeka\EntityManager' => new stdClass(),
            'Omeka\Job\Dispatcher' => new stdClass(),
            'Omeka\RdfImporter' => new stdClass(),
            'Config' => ['educationalcurriculum' => ['uri_base' => 'https://example.org/']],
        ]]);
        $manager = (new ImportManagerFactory())($services, ImportManager::class);
        $services->setService(ImportManager::class, $manager);

        $this->assertInstanceOf(ImportManager::class, $manager);
        $this->assertInstanceOf(
            IndexController::class,
            (new IndexControllerFactory())($services, IndexController::class)
        );
    }

    public function testRegistersThePagesForGlobalAdministrators(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';

        $this->assertSame(
            IndexControllerFactory::class,
            $config['controllers']['factories'][IndexController::class]
        );
        $this->assertSame(ImportManagerFactory::class, $config['service_manager']['factories'][ImportManager::class]);
        $this->assertSame(UploadForm::class, $config['form_elements']['invokables'][UploadForm::class]);
        $route = $config['router']['routes']['admin']['child_routes']['educational-curriculum'];
        $this->assertSame('/educational-curriculum[/:action[/:id]]', $route['options']['route']);
        $this->assertSame(IndexController::class, $route['options']['defaults']['controller']);
        $this->assertSame([
            'label' => 'Curriculum',
            'class' => 'o-icon-items',
            'route' => 'admin/educational-curriculum',
            'resource' => IndexController::class,
            'privilege' => 'index',
        ], $config['navigation']['AdminModule'][0]);
        $this->assertArrayNotHasKey('permissions', $config, 'No ACL rule opens the pages to other roles.');
        $this->assertSame(
            'https://ateeducacion.github.io/curriculo_lomloe_canarias/',
            $config['educationalcurriculum']['uri_base']
        );
    }
}
