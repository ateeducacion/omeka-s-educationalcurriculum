<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Mapping;

use EducationalCurriculum\Mapping\MappedNode;
use EducationalCurriculum\Mapping\MappingException;
use EducationalCurriculum\Mapping\NodeMapper;
use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Release\Release;
use PHPUnit\Framework\TestCase;

class NodeMapperTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/release-1.1.2';
    private const BASE = 'https://ateeducacion.github.io/curriculo_lomloe_canarias/';

    /**
     * The example of docs/omeka.md in the curriculum repository, value by value.
     */
    public function testMapsTheReferenceCriterionOfTheContract(): void
    {
        $release = $this->fixtureRelease();
        $node = $this->findNode($release, 'crit:SMAT01CE1.1');

        $mapped = (new NodeMapper($release))->map($node);

        $this->assertSame('crit:SMAT01CE1.1', $mapped->getIdentifier());
        $this->assertSame('cur:CriterioEvaluacion', $mapped->getResourceClass());
        $this->assertFalse($mapped->isRetired());
        $this->assertSame([
            'dcterms:identifier' => [
                $this->literal('crit:SMAT01CE1.1'),
                $this->uri(self::BASE . 'id/crit/SMAT01CE1.1', 'crit:SMAT01CE1.1'),
            ],
            'skos:notation' => [$this->literal('SMAT01CE1.1')],
            'skos:prefLabel' => [$this->literal($node['prefLabel'])],
            'skos:definition' => [$this->literal($node['definition'])],
            'skos:broader' => [$this->link('ce:SMATC1')],
            'cur:bloqueCompetencial' => [$this->link('bc:SMAT01BC1')],
            'cur:descriptorOperativo' => [
                $this->uri(self::BASE . 'id/descriptor/ESO/CCL1', 'CCL1'),
                $this->uri(self::BASE . 'id/descriptor/ESO/CCL2', 'CCL2'),
                $this->uri(self::BASE . 'id/descriptor/ESO/STEM1', 'STEM1'),
                $this->uri(self::BASE . 'id/descriptor/ESO/CD2', 'CD2'),
                $this->uri(self::BASE . 'id/descriptor/ESO/CC2', 'CC2'),
                $this->uri(self::BASE . 'id/descriptor/ESO/CE3', 'CE3'),
            ],
            'cur:etapa' => [$this->link('etapa:ESO')],
            'cur:curso' => [$this->link('curso:S01')],
            'cur:materia' => [$this->link('materia:SMAT')],
            'cur:materiaCurso' => [$this->link('asig:SMAT01')],
        ], $mapped->getValues());
    }

    public function testMapsANestedBasicKnowledgeItem(): void
    {
        $release = $this->fixtureRelease();

        $mapped = (new NodeMapper($release))->map($this->findNode($release, 'sb:SMAT01SBI.1.1'));

        $this->assertSame('cur:SaberBasico', $mapped->getResourceClass());
        $this->assertSame([$this->link('sb:SMAT01SBI.1')], $mapped->getValues()['skos:broader']);
        $this->assertSame(
            [$this->uri(self::BASE . 'id/sb/SMAT01SBI.1.1', 'sb:SMAT01SBI.1.1')],
            array_slice($mapped->getValues()['dcterms:identifier'], 1)
        );
    }

    public function testMapsTheStageWithoutLinks(): void
    {
        $release = $this->fixtureRelease();

        $mapped = (new NodeMapper($release))->map($this->findNode($release, 'etapa:ESO'));

        $this->assertSame('cur:Etapa', $mapped->getResourceClass());
        $this->assertSame(
            ['dcterms:identifier', 'skos:notation', 'skos:prefLabel'],
            array_keys($mapped->getValues())
        );
    }

    public function testMapsScopeNoteAndGroupedCompetences(): void
    {
        $release = $this->fixtureRelease();
        $node = $this->findNode($release, 'bc:SMAT01BC1');

        $values = (new NodeMapper($release))->map($node)->getValues();

        $this->assertSame([$this->literal($node['scopeNote'])], $values['skos:scopeNote']);
        $this->assertSame(array_map([$this, 'link'], $node['agrupa']), $values['cur:agrupa']);
    }

    public function testMapsEveryNodeInCorpusOrder(): void
    {
        $release = $this->fixtureRelease();

        $mapped = (new NodeMapper($release))->mapAll();

        $this->assertCount(125, $mapped);
        $this->assertContainsOnlyInstancesOf(MappedNode::class, $mapped);
        $this->assertSame(array_column($release->getNodes(), 'id'), array_map(function (MappedNode $node) {
            return $node->getIdentifier();
        }, $mapped));
        $classes = array_values(array_unique(array_map(function (MappedNode $node) {
            return $node->getResourceClass();
        }, $mapped)));
        sort($classes);
        $this->assertSame([
            'cur:BloqueCompetencial', 'cur:BloqueSaberes', 'cur:CompetenciaEspecifica', 'cur:CriterioEvaluacion',
            'cur:Curso', 'cur:Etapa', 'cur:Materia', 'cur:MateriaCurso', 'cur:SaberBasico',
        ], $classes);
    }

    /**
     * ce:PLCLC2 of release 1.1.2: one statement per course, annotated with the course.
     */
    public function testMapsStatementsPerCourseAsAnnotatedLiterals(): void
    {
        $competence = [
            'id' => 'ce:PLCLC2', 'tipo' => 'CompetenciaEspecifica', 'notation' => 'PLCLC2',
            'prefLabel' => 'PLCLC2 · Comprender e interpretar textos orales…',
            'definition' => 'Comprender e interpretar textos orales y multimodales.',
            'enunciados_curso' => ['curso:P01' => 'Statement for 1.º', 'curso:P02' => 'Statement for 2.º'],
            'etapa' => 'etapa:PRI',
        ];
        $release = $this->release([
            ['id' => 'etapa:PRI', 'tipo' => 'Etapa', 'notation' => 'PRI', 'prefLabel' => 'Educación Primaria'],
            $this->course('P01', '1º Primaria'),
            $this->course('P02', '2º Primaria'),
            $competence,
        ]);

        $values = (new NodeMapper($release))->map($competence)->getValues();

        $this->assertSame(
            [$this->literal('Comprender e interpretar textos orales y multimodales.')],
            $values['skos:definition']
        );
        $this->assertSame([
            $this->literal('Statement for 1.º') + ['@annotation' => [
                'cur:curso' => [$this->uri(self::BASE . 'id/curso/P01', '1º Primaria')],
            ]],
            $this->literal('Statement for 2.º') + ['@annotation' => [
                'cur:curso' => [$this->uri(self::BASE . 'id/curso/P02', '2º Primaria')],
            ]],
        ], $values['cur:enunciadoEnCurso']);
    }

    /**
     * materia:IC4M of release 1.1.2, reduced to three courses.
     */
    public function testMapsASubjectWithSeveralCourses(): void
    {
        $subject = [
            'id' => 'materia:IC4M', 'tipo' => 'Materia', 'notation' => 'IC4M',
            'prefLabel' => 'Comunicación y representación de la realidad (Infantil)',
            'cursos' => ['curso:I01', 'curso:I02', 'curso:I03'], 'etapa' => 'etapa:INF',
        ];

        $values = (new NodeMapper($this->release([$subject])))->map($subject)->getValues();

        $this->assertSame(
            [$this->link('curso:I01'), $this->link('curso:I02'), $this->link('curso:I03')],
            $values['cur:curso']
        );
        $this->assertSame([$this->link('etapa:INF')], $values['cur:etapa']);
    }

    public function testMapsARetiredNodeWithItsReplacement(): void
    {
        $retired = [
            'id' => 'crit:SMAT01CE1.9', 'tipo' => 'CriterioEvaluacion', 'notation' => 'SMAT01CE1.9',
            'prefLabel' => 'SMAT01CE1.9 · Retired criterion', 'etapa' => 'etapa:ESO',
            'obsoleto' => true, 'sustituido_por' => 'crit:SMAT01CE1.1',
        ];

        $mapped = (new NodeMapper($this->release([$retired], '1.2.0')))->map($retired);

        $this->assertTrue($mapped->isRetired());
        $this->assertSame([$this->literal('Retirado en la versión 1.2.0')], $mapped->getValues()['skos:changeNote']);
        $this->assertSame([$this->link('crit:SMAT01CE1.1')], $mapped->getValues()['dcterms:isReplacedBy']);
    }

    public function testMapsARetiredNodeWithoutReplacement(): void
    {
        $retired = [
            'id' => 'sb:SMAT01SBI.9', 'tipo' => 'SaberBasico', 'notation' => 'SMAT01SBI.9',
            'prefLabel' => 'SMAT01SBI.9 · Retired knowledge', 'obsoleto' => true,
        ];

        $mapped = (new NodeMapper($this->release([$retired], '1.2.0')))->map($retired);

        $this->assertTrue($mapped->isRetired());
        $this->assertArrayNotHasKey('dcterms:isReplacedBy', $mapped->getValues());
    }

    public function testListsThePropertiesItOwnsInMappingOrder(): void
    {
        $this->assertSame([
            'dcterms:identifier', 'skos:notation', 'skos:prefLabel', 'skos:definition', 'skos:scopeNote',
            'cur:enunciadoEnCurso', 'skos:broader', 'cur:agrupa', 'cur:bloqueCompetencial',
            'cur:descriptorOperativo', 'cur:etapa', 'cur:curso', 'cur:materia', 'cur:materiaCurso',
            'skos:changeNote', 'dcterms:isReplacedBy',
        ], NodeMapper::PROPERTIES);
    }

    /**
     * @dataProvider invalidNodes
     */
    public function testRejectsNodesItCannotMap(array $node, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        (new NodeMapper($this->release([$node])))->map($node);
    }

    public function invalidNodes(): array
    {
        return [
            'unknown type' => [
                ['id' => 'x:1', 'tipo' => 'Desconocido', 'notation' => '1', 'prefLabel' => 'X'],
                'Node x:1 has an unknown tipo "Desconocido".',
            ],
            'id without prefix' => [
                ['id' => 'SMAT01', 'tipo' => 'MateriaCurso', 'notation' => 'SMAT01', 'prefLabel' => 'X'],
                'Node SMAT01 has an id that is not <prefix>:<code>.',
            ],
            'descriptors without a known stage' => [
                ['id' => 'crit:X1', 'tipo' => 'CriterioEvaluacion', 'notation' => 'X1', 'prefLabel' => 'X',
                    'descriptores' => ['CCL1'], 'etapa' => 'etapa:ESO'],
                'Node crit:X1 has descriptores but its etapa etapa:ESO is not in the release.',
            ],
            'statement for an unknown course' => [
                ['id' => 'ce:X1', 'tipo' => 'CompetenciaEspecifica', 'notation' => 'X1', 'prefLabel' => 'X',
                    'enunciados_curso' => ['curso:S09' => 'Text']],
                'Node ce:X1 has a statement for curso:S09, which is not in the release.',
            ],
        ];
    }

    private function fixtureRelease(): Release
    {
        return (new Reader())->read(
            self::FIXTURE . '/curriculo.json',
            self::FIXTURE . '/curriculo.json.sha256',
            self::FIXTURE . '/cur.ttl'
        );
    }

    private function release(array $nodes, string $version = '1.1.2'): Release
    {
        return new Release($version, self::BASE, 'https://creativecommons.org/licenses/by/4.0/', $nodes, '/cur.ttl');
    }

    private function findNode(Release $release, string $id): array
    {
        foreach ($release->getNodes() as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        $this->fail("Node $id is not in the fixture.");
    }

    private function course(string $code, string $label): array
    {
        return ['id' => "curso:$code", 'tipo' => 'Curso', 'notation' => $code, 'prefLabel' => $label,
            'broader' => 'etapa:PRI', 'etapa' => 'etapa:PRI'];
    }

    private function literal(string $value): array
    {
        return ['type' => 'literal', '@value' => $value, '@language' => 'es'];
    }

    private function uri(string $uri, string $label): array
    {
        return ['type' => 'uri', '@id' => $uri, 'o:label' => $label];
    }

    private function link(string $identifier): array
    {
        return ['type' => 'resource:item', 'identifier' => $identifier];
    }
}
