<?php
declare(strict_types=1);

namespace EducationalCurriculum\Controller\Admin;

use EducationalCurriculum\Admin\ImportException;
use EducationalCurriculum\Admin\ImportManager;
use EducationalCurriculum\Entity\CurriculumImport;
use EducationalCurriculum\Form\UploadForm;
use EducationalCurriculum\Release\ReleaseException;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Stdlib\Message;

/**
 * The curriculum pages of the admin. Omeka allows them only to global administrators: the ACL
 * grants other roles nothing on this controller.
 */
class IndexController extends AbstractActionController
{
    const ROUTE = 'admin/educational-curriculum';
    const PER_PAGE = 50;
    const LISTS = ['new', 'modified', 'retired'];

    private $manager;
    private $isUploadedFile;

    /**
     * @param callable|null $isUploadedFile Tells whether a path is a file PHP received in this
     *   request; is_uploaded_file() unless a test replaces it
     */
    public function __construct(ImportManager $manager, ?callable $isUploadedFile = null)
    {
        $this->manager = $manager;
        $this->isUploadedFile = $isUploadedFile ?? 'is_uploaded_file';
    }

    public function indexAction()
    {
        return new ViewModel(['overview' => $this->manager->overview()]);
    }

    public function uploadAction()
    {
        $form = $this->getForm(UploadForm::class);
        $request = $this->getRequest();
        if ($request->isPost()) {
            // File fields come only from the files of this request, never from posted fields.
            $files = array_intersect_key($request->getFiles()->toArray(), array_flip(UploadForm::FILES));
            $post = array_diff_key($request->getPost()->toArray(), array_flip(UploadForm::FILES));
            $form->setData(array_merge($post, $files));
            if (!$form->isValid()) {
                $this->messenger()->addFormErrors($form);
                return new ViewModel(['form' => $form]);
            }
            $paths = $this->uploadedPaths($files);
            if ($paths === null) {
                $this->messenger()->addError('Upload the three files of the release.'); // @translate
            } else {
                try {
                    $import = $this->manager->upload(...array_merge($paths, [$this->identity()]));
                    $this->messenger()->addSuccess(new Message(
                        'Release %s uploaded; its analysis has started.', // @translate
                        $import->getVersion()
                    ));
                    return $this->redirect()->toRoute(self::ROUTE, ['action' => 'show', 'id' => $import->getId()]);
                } catch (ReleaseException | ImportException $e) {
                    $this->messenger()->addError(new Message($e->getTemplate(), ...$e->getArgs()));
                }
            }
        }
        return new ViewModel(['form' => $form]);
    }

    /**
     * Temporary paths of the three files, or null unless each one is a file PHP received.
     */
    private function uploadedPaths(array $files): ?array
    {
        $paths = [];
        foreach (UploadForm::FILES as $name) {
            $path = $files[$name]['tmp_name'] ?? null;
            if (!is_string($path) || ($files[$name]['error'] ?? null) !== UPLOAD_ERR_OK
                || !($this->isUploadedFile)($path)
            ) {
                return null;
            }
            $paths[] = $path;
        }
        return $paths;
    }

    public function showAction()
    {
        $import = $this->import();
        $list = $this->params()->fromQuery('list', 'new');
        if (!in_array($list, self::LISTS, true)) {
            $list = 'new';
        }
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $summary = $this->manager->summary($import);
        $entries = [];
        if ($summary !== null) {
            $entries = $this->manager->page($import, $list, $page, self::PER_PAGE);
            $this->paginator($summary['counts'][$list] ?? 0, $page, self::PER_PAGE);
        }

        $forms = [];
        $status = $import->getStatus();
        if ($status === CurriculumImport::STATUS_READY) {
            $forms['apply'] = $this->confirmForm($import, 'apply', 'Apply'); // @translate
        }
        if (in_array($status, ImportManager::ANALYZABLE, true)) {
            $forms['analyze'] = $this->confirmForm($import, 'analyze', 'Analyze again'); // @translate
        }
        if (!in_array($status, ImportManager::KEPT, true)) {
            $forms['discard'] = $this->confirmForm($import, 'discard', 'Discard'); // @translate
        }

        return new ViewModel([
            'import' => $import,
            'summary' => $summary,
            'list' => $list,
            'entries' => $entries,
            'forms' => $forms,
        ]);
    }

    public function applyAction()
    {
        return $this->confirmed(function (CurriculumImport $import) {
            $this->manager->apply($import);
            $this->messenger()->addSuccess(
                'The release is being applied. This page shows the result when the job ends.' // @translate
            );
        });
    }

    public function analyzeAction()
    {
        return $this->confirmed(function (CurriculumImport $import) {
            $this->manager->analyze($import);
            $this->messenger()->addSuccess('The import is being analyzed again.'); // @translate
        });
    }

    public function discardAction()
    {
        return $this->confirmed(function (CurriculumImport $import) {
            $this->manager->discard($import);
            $this->messenger()->addSuccess('Import discarded.'); // @translate
            return $this->redirect()->toRoute(self::ROUTE, ['action' => 'index']);
        });
    }

    /**
     * Run an action on the import after a confirmed POST, then go back to the import.
     */
    private function confirmed(callable $action)
    {
        $import = $this->import();
        $id = $import->getId();
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                try {
                    $response = $action($import);
                    if ($response !== null) {
                        return $response;
                    }
                } catch (ImportException $e) {
                    $this->messenger()->addError(new Message($e->getTemplate(), ...$e->getArgs()));
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute(self::ROUTE, ['action' => 'show', 'id' => $id]);
    }

    private function confirmForm(CurriculumImport $import, string $action, string $label)
    {
        $form = $this->getForm(ConfirmForm::class);
        $url = $this->url()->fromRoute(self::ROUTE, ['action' => $action, 'id' => $import->getId()]);
        $form->setAttribute('action', $url);
        $form->setButtonLabel($label);
        return $form;
    }

    private function import(): CurriculumImport
    {
        $import = $this->manager->find((int) $this->params()->fromRoute('id'));
        if ($import === null) {
            throw new NotFoundException();
        }
        return $import;
    }
}
