<?php

declare(strict_types=1);

namespace EducationalCurriculumTest;

require dirname(__DIR__) . '/vendor/autoload.php';

require_once __DIR__ . "/Support/Omeka/Module/AbstractModule.php";
require_once __DIR__ . "/Support/Omeka/Entity/AbstractEntity.php";
require_once __DIR__ . "/Support/Omeka/Entity/Job.php";
require_once __DIR__ . "/Support/Omeka/Entity/User.php";
require_once __DIR__ . "/Support/Omeka/Job/AbstractJob.php";
require_once __DIR__ . "/Support/Omeka/Stdlib/Message.php";
require_once __DIR__ . "/Support/Omeka/Form/ConfirmForm.php";
require_once __DIR__ . "/Support/Omeka/Mvc/Exception/NotFoundException.php";
require_once __DIR__ . "/Support/Laminas/Mvc/Controller/AbstractActionController.php";
require_once __DIR__ . "/Support/Laminas/View/Model/ViewModel.php";
require_once dirname(__DIR__) . "/Module.php";
