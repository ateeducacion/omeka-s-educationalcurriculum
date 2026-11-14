<?php
declare(strict_types=1);

namespace EducationalCurriculum\Release;

use EducationalCurriculum\Stdlib\TranslatableMessage;
use RuntimeException;

/**
 * The uploaded files are not a usable curriculum release.
 */
class ReleaseException extends RuntimeException
{
    use TranslatableMessage;
}
