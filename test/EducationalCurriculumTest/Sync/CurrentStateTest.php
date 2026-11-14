<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Sync;

use EducationalCurriculum\Sync\CurrentItem;
use EducationalCurriculum\Sync\CurrentState;
use PHPUnit\Framework\TestCase;

class CurrentStateTest extends TestCase
{
    private const BASE = 'https://ateeducacion.github.io/curriculo_lomloe_canarias/';

    /**
     * Returns the queued rows of each query in order and records the queries.
     */
    private function connection(array $responses)
    {
        return new class ($responses) {
            public $queries = [];
            private $responses;

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function fetchAllAssociative($sql, array $params = [])
            {
                $this->queries[] = [$sql, $params];
                return array_shift($this->responses);
            }

            public function fetchFirstColumn($sql, array $params = [])
            {
                $this->queries[] = [$sql, $params];
                return array_shift($this->responses);
            }
        };
    }

    public function testReadsTheItemsOfTheSetInTheShapeOfTheMapper(): void
    {
        $connection = $this->connection([
            [['id' => '10', 'class' => 'cur:Etapa'], ['id' => '11', 'class' => 'cur:CompetenciaEspecifica']],
            ['11'],
            [
                $this->row(10, 'dcterms:identifier', 'literal', 'etapa:ESO', null, 'es'),
                $this->row(10, 'dcterms:identifier', 'uri', 'etapa:ESO', self::BASE . 'id/etapa/ESO'),
                $this->row(10, 'skos:prefLabel', 'literal', 'Educación Secundaria Obligatoria', null, 'es'),
                $this->row(10, 'dcterms:description', 'literal', 'Not a module property', null, 'es'),
                $this->row(11, 'dcterms:identifier', 'literal', 'ce:SMATC1', null, 'es'),
                $this->row(11, 'cur:enunciadoEnCurso', 'literal', 'Statement for 1.º', null, 'es', null, '501'),
                $this->row(11, 'cur:etapa', 'resource:item', null, null, null, '10'),
            ],
            [
                ['annotation_id' => '501'] + $this->row(0, 'cur:curso', 'uri', '1º ESO', self::BASE . 'id/curso/S01'),
            ],
        ]);

        $items = (new CurrentState($connection))->read(7, 8);

        $this->assertEquals([
            new CurrentItem(10, 'cur:Etapa', [
                'dcterms:identifier' => [
                    $this->literal('etapa:ESO'),
                    ['type' => 'uri', '@id' => self::BASE . 'id/etapa/ESO', 'o:label' => 'etapa:ESO'],
                ],
                'skos:prefLabel' => [$this->literal('Educación Secundaria Obligatoria')],
            ], false),
            new CurrentItem(11, 'cur:CompetenciaEspecifica', [
                'dcterms:identifier' => [$this->literal('ce:SMATC1')],
                'cur:enunciadoEnCurso' => [$this->literal('Statement for 1.º') + ['@annotation' => [
                    'cur:curso' => [['type' => 'uri', '@id' => self::BASE . 'id/curso/S01', 'o:label' => '1º ESO']],
                ]]],
                'cur:etapa' => [['type' => 'resource:item', 'identifier' => 'etapa:ESO']],
            ], true),
        ], $items);
        $this->assertSame([7], $connection->queries[0][1]);
        $this->assertSame([8], $connection->queries[1][1]);
        $this->assertSame([7], $connection->queries[2][1]);
        $this->assertSame([7], $connection->queries[3][1]);
    }

    public function testKeepsLinksToItemsOutsideTheSetByItemId(): void
    {
        $connection = $this->connection([
            [['id' => '10', 'class' => 'cur:Curso']],
            [],
            [
                $this->row(10, 'dcterms:identifier', 'literal', 'curso:S01', null, 'es'),
                $this->row(10, 'cur:etapa', 'resource:item', null, null, null, '999'),
            ],
            [],
        ]);

        $items = (new CurrentState($connection))->read(7, 8);

        $this->assertSame(
            [['type' => 'resource:item', 'identifier' => null, 'item' => 999]],
            $items[0]->getValues()['cur:etapa']
        );
    }

    public function testKeepsTheDifferencesThatMustBeRewritten(): void
    {
        $connection = $this->connection([
            [['id' => '10', 'class' => null]],
            [
                $this->row(10, 'dcterms:identifier', 'literal', 'curso:S01', null, ''),
                $this->row(10, 'skos:prefLabel', 'customvocab:3', '1º ESO', null, null),
            ],
            [],
        ]);

        $items = (new CurrentState($connection))->read(7, null);

        $this->assertNull($items[0]->getResourceClass());
        $this->assertFalse($items[0]->isRetired());
        $this->assertSame([
            'dcterms:identifier' => [['type' => 'literal', '@value' => 'curso:S01', '@language' => null]],
            'skos:prefLabel' => [['type' => 'customvocab:3', '@value' => '1º ESO', '@id' => null]],
        ], $items[0]->getValues());
        $this->assertCount(3, $connection->queries);
    }

    public function testListsTheTermsOfAnInstalledVocabulary(): void
    {
        $connection = $this->connection([['Etapa', 'Curso'], ['etapa', 'curso']]);
        $namespace = self::BASE . 'def/cur#';

        $terms = (new CurrentState($connection))->vocabularyTerms($namespace, 'cur');

        $this->assertSame(['cur:Etapa', 'cur:Curso', 'cur:etapa', 'cur:curso'], $terms);
        $this->assertSame([$namespace], $connection->queries[0][1]);
        $this->assertSame([$namespace], $connection->queries[1][1]);
    }

    private function row(
        int $resourceId,
        string $term,
        string $type,
        ?string $value,
        ?string $uri = null,
        ?string $lang = null,
        ?string $valueResourceId = null,
        ?string $annotationId = null
    ): array {
        return [
            'resource_id' => (string) $resourceId,
            'term' => $term,
            'type' => $type,
            'value' => $value,
            'uri' => $uri,
            'lang' => $lang,
            'value_resource_id' => $valueResourceId,
            'value_annotation_id' => $annotationId,
        ];
    }

    private function literal(string $value): array
    {
        return ['type' => 'literal', '@value' => $value, '@language' => 'es'];
    }
}
