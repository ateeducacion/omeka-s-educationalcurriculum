<?php
declare(strict_types=1);

namespace EducationalCurriculum;

return [
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
    ],
    'service_manager' => [
        'factories' => [
            Sync\Synchronizer::class => Service\SynchronizerFactory::class,
            Admin\ImportManager::class => Service\ImportManagerFactory::class,
        ],
    ],
    // Registered controllers are ACL resources; only global administrators may use this one.
    'controllers' => [
        'factories' => [
            Controller\Admin\IndexController::class => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\UploadForm::class => Form\UploadForm::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'educational-curriculum' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/educational-curriculum[/:action[/:id]]',
                            'constraints' => [
                                'action' => 'index|upload|show|apply|analyze|discard',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'EducationalCurriculum\Controller\Admin',
                                'controller' => Controller\Admin\IndexController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Curriculum', // @translate
                'class' => 'o-icon-items',
                'route' => 'admin/educational-curriculum',
                'resource' => Controller\Admin\IndexController::class,
                'privilege' => 'index',
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'educationalcurriculum' => [
        // Base of the published URIs of the curriculum (corpus.uri_base), from the contract in the
        // curriculum repository. It locates the item sets before any upload.
        'uri_base' => 'https://ateeducacion.github.io/curriculo_lomloe_canarias/',
    ],
];
