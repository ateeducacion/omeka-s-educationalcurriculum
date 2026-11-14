<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Admin;

use EducationalCurriculum\Admin\ReportErrors;
use EducationalCurriculum\Sync\Plan;
use PHPUnit\Framework\TestCase;

class ReportErrorsTest extends TestCase
{
    /**
     * @dataProvider errors
     */
    public function testDescribesEachBlockingError(array $error, string $template, array $args): void
    {
        $this->assertSame([$template, $args], ReportErrors::message($error));
    }

    public function errors(): array
    {
        return [
            'unknown identifier' => [
                $this->error(Plan::ERROR_UNKNOWN_IDENTIFIER, 'etapa:X', 9),
                'The item %s is in the curriculum item set but not in the release.',
                ['etapa:X'],
            ],
            'class mismatch' => [
                $this->error(Plan::ERROR_CLASS_MISMATCH, 'etapa:ESO', 9, [
                    'current' => 'cur:Materia',
                    'expected' => 'cur:Etapa',
                ]),
                'The item %s has the class %s, but its node is a %s.',
                ['etapa:ESO', 'cur:Materia', 'cur:Etapa'],
            ],
            'unretired' => [
                $this->error(Plan::ERROR_UNRETIRED, 'crit:X1', 9),
                'The item %s is retired in Omeka but current in the release.',
                ['crit:X1'],
            ],
            'duplicate identifier' => [
                $this->error(Plan::ERROR_DUPLICATE_IDENTIFIER, 'etapa:ESO', 9),
                'Several items of the curriculum item set have the identifier %s.',
                ['etapa:ESO'],
            ],
            'missing identifier' => [
                $this->error(Plan::ERROR_MISSING_IDENTIFIER, null, 9),
                'An item of the curriculum item set has no literal dcterms:identifier.',
                [],
            ],
            'unknown link' => [
                $this->error(Plan::ERROR_UNKNOWN_LINK, 'curso:S01', null, [
                    'property' => 'cur:etapa',
                    'target' => 'etapa:X',
                ]),
                'The node %s links through %s to %s, which is not in the release.',
                ['curso:S01', 'cur:etapa', 'etapa:X'],
            ],
            'vocabulary term removed' => [
                $this->error(Plan::ERROR_VOCABULARY_TERM_REMOVED, null, null, ['term' => 'cur:etapa']),
                'The new cur.ttl removes %s, which items use.',
                ['cur:etapa'],
            ],
            'vocabulary term missing' => [
                $this->error(Plan::ERROR_VOCABULARY_TERM_MISSING, null, null, ['term' => 'cur:etapa']),
                'The release uses %s, which its cur.ttl does not define.',
                ['cur:etapa'],
            ],
            'unknown code' => [
                $this->error('other', null, null),
                'Unknown error %s.',
                ['other'],
            ],
        ];
    }

    private function error(string $code, ?string $identifier, ?int $item, array $detail = []): array
    {
        return ['code' => $code, 'identifier' => $identifier, 'item' => $item, 'detail' => $detail];
    }
}
