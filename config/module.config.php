<?php
namespace PreservicaConnector;

return [
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
    'service_manager' => [
        'factories' => [
            Preservica\Client::class => Service\ClientFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'PreservicaConnector\Controller\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'preservica' => Service\Media\Ingester\PreservicaFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ImportForm::class => Form\ImportForm::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Preservica Connector', // @translate
                'route' => 'admin/preservica-connector',
                'resource' => 'PreservicaConnector\Controller\Index',
                'pages' => [
                    [
                        'label' => 'Search and import', // @translate
                        'route' => 'admin/preservica-connector',
                        'resource' => 'PreservicaConnector\Controller\Index',
                    ],
                    [
                        'label' => 'Past imports', // @translate
                        'route' => 'admin/preservica-connector/past-imports',
                        'resource' => 'PreservicaConnector\Controller\Index',
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'preservica-connector' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/preservica-connector',
                            'defaults' => [
                                '__NAMESPACE__' => 'PreservicaConnector\Controller',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'import' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/import',
                                    'defaults' => ['action' => 'import'],
                                ],
                            ],
                            'past-imports' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/past-imports',
                                    'defaults' => ['action' => 'past-imports'],
                                ],
                            ],
                            'thumbnail' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/thumbnail/:ref',
                                    'constraints' => [
                                        'ref' => '[0-9a-fA-F-]{36}',
                                    ],
                                    'defaults' => ['action' => 'thumbnail'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
