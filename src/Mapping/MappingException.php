<?php
declare(strict_types=1);

namespace EducationalCurriculum\Mapping;

use RuntimeException;

/**
 * A node of the release cannot be mapped to an Omeka item.
 */
class MappingException extends RuntimeException
{
}
