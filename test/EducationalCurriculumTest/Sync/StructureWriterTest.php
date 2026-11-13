<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Sync;

use EducationalCurriculum\Release\Release;
use EducationalCurriculum\Sync\Structure;
use EducationalCurriculum\Sync\StructureException;
use EducationalCurriculum\Sync\StructureWriter;
use EducationalCurriculumTest\Support\FakeApi;
use EducationalCurriculumTest\Support\FakeResource;
use PHPUnit\Framework\TestCase;

class StructureWriterTest extends TestCase
{
    private const BASE = 'https://ateeducacion.github.io/curriculo_lomloe_canarias/';
    private const LICENSE = 'https://creativecommons.org/licenses/by/4.0/';
    private const PROPERTIES = [
        'dcterms:title' => 1, 'dcterms:identifier' => 10, 'dcterms:license' => 49, 'dcterms:hasVersion' => 33,
        'skos:prefLabel' => 200, 'skos:definition' => 201, 'skos:notation' => 202, 'cur:etapa' => 300,
    ];

    private $api;
    private $importer;
    private $writer;

    protected function setUp(): void
    {
        $this->api = new FakeApi();
        foreach (self::PROPERTIES as $term => $id) {
            $this->api->withTerm('properties', $term, $id);
        }
        $this->api->withTerm('resource_classes', 'skos:ConceptScheme', 50, 'Concept Scheme');
        $this->api->withTerm('resource_classes', 'cur:Etapa', 60, 'Etapa');
        $this->api->withTerm('resource_classes', 'cur:Curso', 61, 'Curso');
        $this->importer = new class {
            public $calls = [];

            public function import($strategy, array $vocab, array $options = [])
            {
                $this->calls[] = ['import', $strategy, $vocab, $options];
            }

            public function getDiff($strategy, $namespaceUri, array $options = [])
            {
                $this->calls[] = ['getDiff', $strategy, $namespaceUri, $options];
                return ['classes' => ['new' => ['Nueva' => ['Nueva', '']]]];
            }

            public function update($vocabId, array $diff)
            {
                $this->calls[] = ['update', $vocabId, $diff];
            }
        };
        $this->writer = new StructureWriter($this->api, $this->importer);
    }

    public function testImportsTheCurVocabularyWithSpanishLabels(): void
    {
        $this->writer->ensureVocabulary($this->release());

        $this->assertSame([['import', 'file', [
            'o:namespace_uri' => self::BASE . 'def/cur#',
            'o:prefix' => 'cur',
            'o:label' => 'Currículo LOMLOE de Canarias',
        ], ['file' => '/release/cur.ttl', 'format' => 'turtle', 'lang' => 'es']]], $this->importer->calls);
    }

    public function testUpdatesAnInstalledCurVocabulary(): void
    {
        $this->api->willFind('vocabularies', ['namespace_uri' => self::BASE . 'def/cur#'], [new FakeResource(7)]);

        $this->writer->ensureVocabulary($this->release());

        $options = ['file' => '/release/cur.ttl', 'format' => 'turtle', 'lang' => 'es'];
        $this->assertSame([
            ['getDiff', 'file', self::BASE . 'def/cur#', $options],
            ['update', 7, ['classes' => ['new' => ['Nueva' => ['Nueva', '']]]]],
        ], $this->importer->calls);
    }

    public function testCreatesTheTemplatesAndItemSetsOnAFirstLoad(): void
    {
        $structure = $this->writer->prepare($this->release(), [
            'cur:Etapa' => ['dcterms:identifier', 'skos:notation', 'skos:prefLabel'],
            'cur:Curso' => ['dcterms:identifier', 'skos:prefLabel', 'cur:etapa'],
        ]);

        $this->assertSame([
            [[
                'o:label' => 'Currículo · Etapa',
                'o:resource_class' => ['o:id' => 60],
                'o:title_property' => ['o:id' => 200],
                'o:description_property' => ['o:id' => 201],
                'o:resource_template_property' => [
                    ['o:property' => ['o:id' => 10]],
                    ['o:property' => ['o:id' => 202]],
                    ['o:property' => ['o:id' => 200]],
                ],
            ]],
            [[
                'o:label' => 'Currículo · Curso',
                'o:resource_class' => ['o:id' => 61],
                'o:title_property' => ['o:id' => 200],
                'o:description_property' => ['o:id' => 201],
                'o:resource_template_property' => [
                    ['o:property' => ['o:id' => 10]],
                    ['o:property' => ['o:id' => 200]],
                    ['o:property' => ['o:id' => 300]],
                ],
            ]],
        ], $this->api->callsTo('create', 'resource_templates'));
        $this->assertSame([
            [$this->curriculumSet()],
            [$this->retiredSet()],
        ], $this->api->callsTo('create', 'item_sets'));
        $this->assertInstanceOf(Structure::class, $structure);
        $this->assertSame(1000, $structure->getTemplateId('cur:Etapa'));
        $this->assertSame(1001, $structure->getTemplateId('cur:Curso'));
        $this->assertSame(1002, $structure->getCurriculumItemSetId());
        $this->assertSame(1003, $structure->getRetiredItemSetId());
        $this->assertSame(60, $structure->getClassId('cur:Etapa'));
        $this->assertSame(300, $structure->getPropertyId('cur:etapa'));
    }

    public function testKeepsExistingTemplatesAndUpdatesTheItemSetsItOwns(): void
    {
        $this->api->willFind('resource_templates', ['label' => 'Currículo · Etapa'], [new FakeResource(70)]);
        $this->findItemSet('id/curriculo', 80);
        $this->findItemSet('id/curriculo/retirados', 81);

        $structure = $this->writer->prepare($this->release(), ['cur:Etapa' => ['skos:prefLabel']]);

        $this->assertSame([], $this->api->callsTo('create', 'resource_templates'));
        $this->assertSame([], $this->api->callsTo('create', 'item_sets'));
        $append = ['isPartial' => true, 'collectionAction' => 'append'];
        $this->assertSame([
            [80, ['clear_property_values' => [1, 200, 10, 49]] + $this->curriculumSet(), $append],
            [81, ['clear_property_values' => [1, 10]] + $this->retiredSet(), $append],
        ], $this->api->callsTo('update', 'item_sets'));
        $this->assertSame(70, $structure->getTemplateId('cur:Etapa'));
        $this->assertSame(80, $structure->getCurriculumItemSetId());
        $this->assertSame(81, $structure->getRetiredItemSetId());
    }

    public function testFindsTheItemSetsOfAnInstance(): void
    {
        $this->assertSame(['curriculum' => null, 'retired' => null], $this->writer->findItemSets(self::BASE));

        $this->findItemSet('id/curriculo', 80);

        $this->assertSame(['curriculum' => 80, 'retired' => null], $this->writer->findItemSets(self::BASE));
    }

    public function testRecordsTheLoadedVersionLast(): void
    {
        $structure = $this->writer->prepare($this->release(), []);

        $this->writer->setVersion($structure, '1.1.2');

        $this->assertSame([[$structure->getCurriculumItemSetId(), [
            'clear_property_values' => [33],
            'dcterms:hasVersion' => [['type' => 'literal', 'property_id' => 33, '@value' => '1.1.2']],
        ], ['isPartial' => true, 'collectionAction' => 'append']]], $this->api->callsTo('update', 'item_sets'));
    }

    public function testRejectsATermThatIsNotInstalled(): void
    {
        $this->expectException(StructureException::class);
        $this->expectExceptionMessage('The property cur:missing is not installed.');

        $this->writer->prepare($this->release(), ['cur:Etapa' => ['cur:missing']]);
    }

    public function testRejectsAClassThatIsNotInstalled(): void
    {
        $this->expectException(StructureException::class);
        $this->expectExceptionMessage('The resource class cur:Missing is not installed.');

        $this->writer->prepare($this->release(), ['cur:Missing' => []]);
    }

    public function testStructureRejectsUnknownTerms(): void
    {
        $structure = $this->writer->prepare($this->release(), []);

        $this->expectException(StructureException::class);
        $this->expectExceptionMessage('The property cur:other is not installed.');
        $structure->getPropertyId('cur:other');
    }

    private function release(): Release
    {
        return new Release('1.1.2', self::BASE, self::LICENSE, [], '/release/cur.ttl');
    }

    private function findItemSet(string $path, int $id): void
    {
        $this->api->willFind('item_sets', ['property' => [
            ['property' => 'dcterms:identifier', 'type' => 'eq', 'text' => self::BASE . $path],
        ]], [new FakeResource($id)]);
    }

    private function curriculumSet(): array
    {
        return [
            'o:resource_class' => ['o:id' => 50],
            'o:is_public' => true,
            'dcterms:title' => [$this->literal(1, 'Currículo LOMLOE de Canarias')],
            'skos:prefLabel' => [$this->literal(200, 'Currículo LOMLOE de Canarias')],
            'dcterms:identifier' => [['type' => 'uri', 'property_id' => 10, '@id' => self::BASE . 'id/curriculo']],
            'dcterms:license' => [['type' => 'uri', 'property_id' => 49, '@id' => self::LICENSE]],
        ];
    }

    private function retiredSet(): array
    {
        return [
            'o:is_public' => true,
            'dcterms:title' => [$this->literal(1, 'Currículo · Retirados')],
            'dcterms:identifier' => [
                ['type' => 'uri', 'property_id' => 10, '@id' => self::BASE . 'id/curriculo/retirados'],
            ],
        ];
    }

    private function literal(int $propertyId, string $value): array
    {
        return ['type' => 'literal', 'property_id' => $propertyId, '@value' => $value, '@language' => 'es'];
    }
}
