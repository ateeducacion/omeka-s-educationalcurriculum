<?php
declare(strict_types=1);

namespace EducationalCurriculum\Admin;

use EducationalCurriculum\Stdlib\TranslatableMessage;
use RuntimeException;

/**
 * The requested action does not fit the state of the imports.
 */
class ImportException extends RuntimeException
{
    use TranslatableMessage;
}
