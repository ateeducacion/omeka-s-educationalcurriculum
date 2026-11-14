<?php
declare(strict_types=1);

namespace Laminas\Mvc\Controller;

use EducationalCurriculumTest\Support\ControllerContext;

/**
 * The controller plugins the module uses, answered by a test context.
 */
abstract class AbstractActionController
{
    private $context;

    public function setContext(ControllerContext $context): void
    {
        $this->context = $context;
    }

    public function getRequest()
    {
        return $this->context->request;
    }

    public function params()
    {
        return $this->context->params;
    }

    public function messenger()
    {
        return $this->context->messenger;
    }

    public function redirect()
    {
        return $this->context->redirect;
    }

    public function getForm($class, ?array $options = null)
    {
        return $this->context->form($class);
    }

    public function url()
    {
        return $this->context->url;
    }

    public function identity()
    {
        return $this->context->identity;
    }

    public function paginator($totalCount, $currentPage = null, $perPage = null)
    {
        $this->context->paginator = [$totalCount, $currentPage, $perPage];
    }
}
