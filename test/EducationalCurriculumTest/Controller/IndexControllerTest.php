<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Controller;

use EducationalCurriculum\Admin\ImportException;
use EducationalCurriculum\Admin\ImportManager;
use EducationalCurriculum\Controller\Admin\IndexController;
use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Form\UploadForm;
use EducationalCurriculum\Release\ReleaseException;
use EducationalCurriculumTest\Support\ControllerContext;
use EducationalCurriculumTest\Support\FakeEntityManager;
use Omeka\Entity\User;
use Omeka\Form\ConfirmForm;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Stdlib\Message;
use PHPUnit\Framework\TestCase;

class IndexControllerTest extends TestCase
{
    private const ROUTE = 'admin/educational-curriculum';

    private $manager;
    private $context;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(ImportManager::class);
    }

    public function testShowsTheOverview(): void
    {
        $overview = ['version' => '1.1.2', 'appliedAt' => null, 'active' => 1, 'retired' => 0, 'imports' => []];
        $this->manager->method('overview')->willReturn($overview);

        $view = $this->controller()->indexAction();

        $this->assertSame($overview, $view->getVariable('overview'));
    }

    public function testShowsTheUploadForm(): void
    {
        $form = $this->uploadForm(false);

        $view = $this->controller([], [], null, $form)->uploadAction();

        $this->assertSame($form, $view->getVariable('form'));
        $this->assertSame([], $this->context->messenger->messages);
    }

    public function testUploadsARelease(): void
    {
        $owner = new User();
        $import = $this->import(4, CurriculumImport::STATUS_ANALYZING);
        $this->manager->expects($this->once())->method('upload')
            ->with('/tmp/json', '/tmp/sha', '/tmp/ttl', $owner)->willReturn($import);
        $controller = $this->controller([], [], ['csrf' => 'x'], $this->uploadForm(true), $this->files());
        $this->context->identity = $owner;

        $result = $controller->uploadAction();

        $this->assertSame(['redirect', self::ROUTE, ['action' => 'show', 'id' => 4]], $result);
        [$type, $message] = $this->context->messenger->messages[0];
        $this->assertSame('success', $type);
        $this->assertSame('Release %s uploaded; its analysis has started.', $message->getMessage());
        $this->assertSame(['1.1.2'], $message->getArgs());
    }

    public function testShowsWhyAnUploadWasRejected(): void
    {
        $this->manager->method('upload')->willThrowException(
            new ReleaseException('Version %s is older than the loaded version %s.', '1.1.2', '1.2.0')
        );
        $form = $this->uploadForm(true);

        $view = $this->controller([], [], ['csrf' => 'x'], $form, $this->files())->uploadAction();

        $this->assertSame($form, $view->getVariable('form'));
        [$type, $message] = $this->context->messenger->messages[0];
        $this->assertSame('error', $type);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('Version %s is older than the loaded version %s.', $message->getMessage());
        $this->assertSame(['1.1.2', '1.2.0'], $message->getArgs());
    }

    public function testDoesNotUploadAnInvalidForm(): void
    {
        $this->manager->expects($this->never())->method('upload');
        $form = $this->uploadForm(false);

        $this->controller([], [], ['csrf' => 'bad'], $form, $this->files())->uploadAction();

        $this->assertSame([['form', $form]], $this->context->messenger->messages);
    }

    public function testShowsAnImportWithOnePageOfItsReport(): void
    {
        $import = $this->import(4, CurriculumImport::STATUS_READY);
        $summary = ['counts' => ['new' => 3, 'modified' => 120, 'retired' => 0], 'errors' => []];
        $this->manager->method('find')->with(4)->willReturn($import);
        $this->manager->method('summary')->willReturn($summary);
        $this->manager->expects($this->once())->method('page')
            ->with($import, 'modified', 2, IndexController::PER_PAGE)->willReturn([['identifier' => 'sb:1']]);

        $view = $this->controller(['id' => '4'], ['list' => 'modified', 'page' => '2'])->showAction();

        $this->assertSame($import, $view->getVariable('import'));
        $this->assertSame($summary, $view->getVariable('summary'));
        $this->assertSame('modified', $view->getVariable('list'));
        $this->assertSame([['identifier' => 'sb:1']], $view->getVariable('entries'));
        $this->assertSame([120, 2, IndexController::PER_PAGE], $this->context->paginator);
        $this->assertSame(['apply', 'analyze', 'discard'], array_keys($view->getVariable('forms')));
        $apply = $view->getVariable('forms')['apply'];
        $this->assertInstanceOf(ConfirmForm::class, $apply);
        $this->assertSame('Apply', $apply->buttonLabel);
        $this->assertSame('url:admin/educational-curriculum/apply/4', $apply->attributes['action']);
    }

    public function testShowsTheNewItemsOfAnImportBeingAnalyzed(): void
    {
        $import = $this->import(4, CurriculumImport::STATUS_ANALYZING);
        $this->manager->method('find')->willReturn($import);
        $this->manager->method('summary')->willReturn(null);
        $this->manager->expects($this->never())->method('page');

        $view = $this->controller(['id' => '4'], ['list' => 'other', 'page' => '-3'])->showAction();

        $this->assertSame('new', $view->getVariable('list'));
        $this->assertSame([], $view->getVariable('entries'));
        $this->assertSame([], $view->getVariable('forms'));
    }

    /**
     * @dataProvider actionsByStatus
     */
    public function testOffersTheActionsOfEachStatus(string $status, array $actions): void
    {
        $this->manager->method('find')->willReturn($this->import(4, $status));

        $view = $this->controller(['id' => '4'])->showAction();

        $this->assertSame($actions, array_keys($view->getVariable('forms')));
    }

    public function actionsByStatus(): array
    {
        return [
            [CurriculumImport::STATUS_BLOCKED, ['analyze', 'discard']],
            [CurriculumImport::STATUS_STALE, ['analyze', 'discard']],
            [CurriculumImport::STATUS_INTERRUPTED, ['analyze', 'discard']],
            [CurriculumImport::STATUS_APPLYING, []],
            [CurriculumImport::STATUS_APPLIED, []],
        ];
    }

    public function testAnUnknownImportIsNotFound(): void
    {
        $this->manager->method('find')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->controller(['id' => '99'])->showAction();
    }

    public function testAppliesAnImport(): void
    {
        $import = $this->import(4, CurriculumImport::STATUS_READY);
        $this->manager->method('find')->willReturn($import);
        $this->manager->expects($this->once())->method('apply')->with($import);

        $result = $this->controller(['id' => '4'], [], ['csrf' => 'x'])->applyAction();

        $this->assertSame(['redirect', self::ROUTE, ['action' => 'show', 'id' => 4]], $result);
        $this->assertSame('success', $this->context->messenger->messages[0][0]);
    }

    public function testShowsWhyAnImportCannotBeApplied(): void
    {
        $this->manager->method('find')->willReturn($this->import(4, CurriculumImport::STATUS_BLOCKED));
        $this->manager->method('apply')->willThrowException(
            new ImportException('Only an analyzed import without errors can be applied.')
        );

        $this->controller(['id' => '4'], [], ['csrf' => 'x'])->applyAction();

        [$type, $message] = $this->context->messenger->messages[0];
        $this->assertSame('error', $type);
        $this->assertSame('Only an analyzed import without errors can be applied.', $message->getMessage());
    }

    public function testActionsNeedAValidPost(): void
    {
        $this->manager->method('find')->willReturn($this->import(4, CurriculumImport::STATUS_READY));
        $this->manager->expects($this->never())->method('apply');

        $result = $this->controller(['id' => '4'])->applyAction();
        $this->assertSame(['redirect', self::ROUTE, ['action' => 'show', 'id' => 4]], $result);

        $form = new ConfirmForm();
        $form->valid = false;
        $controller = $this->controller(['id' => '4'], [], ['csrf' => 'bad']);
        $this->context->forms[ConfirmForm::class] = $form;
        $controller->applyAction();
        $this->assertSame([['form', $form]], $this->context->messenger->messages);
    }

    public function testAnalyzesAnImportAgain(): void
    {
        $import = $this->import(4, CurriculumImport::STATUS_STALE);
        $this->manager->method('find')->willReturn($import);
        $this->manager->expects($this->once())->method('analyze')->with($import);

        $result = $this->controller(['id' => '4'], [], ['csrf' => 'x'])->analyzeAction();

        $this->assertSame(['redirect', self::ROUTE, ['action' => 'show', 'id' => 4]], $result);
    }

    public function testDiscardsAnImport(): void
    {
        $import = $this->import(4, CurriculumImport::STATUS_BLOCKED);
        $this->manager->method('find')->willReturn($import);
        $this->manager->expects($this->once())->method('discard')->with($import);

        $result = $this->controller(['id' => '4'], [], ['csrf' => 'x'])->discardAction();

        $this->assertSame(['redirect', self::ROUTE, ['action' => 'index']], $result);
    }

    private function controller(
        array $route = [],
        array $query = [],
        ?array $post = null,
        $uploadForm = null,
        array $files = []
    ): IndexController {
        $this->context = new ControllerContext($route, $query, $post, $files);
        $this->context->identity = new User();
        if ($uploadForm !== null) {
            $this->context->forms[UploadForm::class] = $uploadForm;
        }
        $controller = new IndexController($this->manager);
        $controller->setContext($this->context);
        return $controller;
    }

    private function uploadForm(bool $valid)
    {
        return new class ($valid) {
            public $data;
            private $valid;

            public function __construct(bool $valid)
            {
                $this->valid = $valid;
            }

            public function setData($data)
            {
                $this->data = $data;
            }

            public function isValid()
            {
                return $this->valid;
            }

            public function getData()
            {
                return $this->data;
            }
        };
    }

    private function files(): array
    {
        return [
            'curriculo_json' => ['tmp_name' => '/tmp/json', 'name' => 'curriculo.json', 'error' => 0],
            'curriculo_sha256' => ['tmp_name' => '/tmp/sha', 'name' => 'curriculo.json.sha256', 'error' => 0],
            'cur_ttl' => ['tmp_name' => '/tmp/ttl', 'name' => 'cur.ttl', 'error' => 0],
        ];
    }

    private function import(int $id, string $status): CurriculumImport
    {
        $import = new CurriculumImport();
        $import->setVersion('1.1.2');
        $import->setStatus($status);
        return (new FakeEntityManager())->add($import, $id);
    }
}
