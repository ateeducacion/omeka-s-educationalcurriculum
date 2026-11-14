<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

/**
 * Request, route and query parameters and controller plugins for a controller under test.
 */
class ControllerContext
{
    public $request;
    public $params;
    public $messenger;
    public $redirect;
    public $url;
    public $identity;
    public $paginator;

    /**
     * Forms getForm() returns for a class; other classes get a new instance on each call.
     */
    public $forms = [];
    public $created = [];

    public function __construct(array $route = [], array $query = [], ?array $post = null, array $files = [])
    {
        $this->request = new class ($post, $files) {
            private $post;
            private $files;

            public function __construct(?array $post, array $files)
            {
                $this->post = $post;
                $this->files = $files;
            }

            public function isPost()
            {
                return $this->post !== null;
            }

            public function getPost()
            {
                return new FakeParameters($this->post ?? []);
            }

            public function getFiles()
            {
                return new FakeParameters($this->files);
            }
        };
        $this->params = new class ($route, $query) {
            private $route;
            private $query;

            public function __construct(array $route, array $query)
            {
                $this->route = $route;
                $this->query = $query;
            }

            public function fromRoute($name, $default = null)
            {
                return $this->route[$name] ?? $default;
            }

            public function fromQuery($name, $default = null)
            {
                return $this->query[$name] ?? $default;
            }
        };
        $this->messenger = new class {
            public $messages = [];

            public function addSuccess($message)
            {
                $this->messages[] = ['success', $message];
            }

            public function addError($message)
            {
                $this->messages[] = ['error', $message];
            }

            public function addFormErrors($form)
            {
                $this->messages[] = ['form', $form];
            }
        };
        $this->url = new class {
            public function fromRoute($route = null, $params = [], $options = [], $reuseMatchedParams = false)
            {
                return 'url:' . $route . '/' . implode('/', $params);
            }
        };
        $this->redirect = new class {
            public function toRoute($route = null, $params = [], $options = [], $reuseMatchedParams = false)
            {
                return ['redirect', $route, $params];
            }
        };
    }

    public function form(string $class)
    {
        if (isset($this->forms[$class])) {
            return $this->forms[$class];
        }
        $form = new $class();
        $this->created[] = $form;
        return $form;
    }
}
