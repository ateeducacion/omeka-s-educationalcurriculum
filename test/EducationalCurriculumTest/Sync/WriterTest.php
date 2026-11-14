<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Sync;

use EducationalCurriculum\Sync\Plan;
use EducationalCurriculum\Sync\Structure;
use EducationalCurriculum\Sync\Writer;
use EducationalCurriculumTest\Support\FakeApi;
use Omeka\Entity\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WriterTest extends TestCase
{
    private const BASE = 'https://ateeducacion.github.io/curriculo_lomloe_canarias/';
    private const APPEND = ['isPartial' => true, 'collectionAction' => 'append'];

    private $api;
    private $entityManager;
    private $authentication;
    private $log;
    private $structure;

    protected function setUp(): void
    {
        $this->api = new FakeApi();
        $this->entityManager = new class {
            public $clears = 0;

            public function clear()
            {
                $this->clears++;
            }

            public function getReference($class, $id)
            {
                return ['reference', $class, $id];
            }
        };
        $this->authentication = new class {
            public $identity;
            public $written = [];

            public function getIdentity()
            {
                return $this->identity;
            }

            public function getStorage()
            {
                return $this;
            }

            public function write($identity)
            {
                $this->identity = $identity;
                $this->written[] = $identity;
            }
        };
        $this->log = [];
        $this->structure = new Structure(
            ['dcterms:identifier' => 10, 'skos:prefLabel' => 200, 'skos:scopeNote' => 203,
                'cur:enunciadoEnCurso' => 301, 'cur:etapa' => 300, 'cur:curso' => 302,
                'skos:changeNote' => 204, 'dcterms:isReplacedBy' => 11],
            ['cur:Etapa' => 60, 'cur:CompetenciaEspecifica' => 64, 'cur:CriterioEvaluacion' => 66],
            ['cur:Etapa' => 70, 'cur:CompetenciaEspecifica' => 74],
            80,
            81
        );
    }

    public function testCreatesNewItemsWithoutLinksFirst(): void
    {
        $plan = $this->plan(['new' => [$this->newItem('ce:X1', 'cur:CompetenciaEspecifica', [
            'dcterms:identifier' => [
                $this->literal('ce:X1'),
                ['type' => 'uri', '@id' => self::BASE . 'id/ce/X1', 'o:label' => 'ce:X1'],
            ],
            'cur:enunciadoEnCurso' => [$this->literal('Statement') + ['@annotation' => [
                'cur:curso' => [['type' => 'uri', '@id' => self::BASE . 'id/curso/S01', 'o:label' => '1º ESO']],
            ]]],
            'cur:etapa' => [$this->link('etapa:ESO')],
        ])]]);

        $this->writer()->apply($plan, $this->structure, ['etapa:ESO' => 5]);

        $this->assertSame([[[
            'o:resource_class' => ['o:id' => 64],
            'o:resource_template' => ['o:id' => 74],
            'o:item_set' => [['o:id' => 80]],
            'o:is_public' => true,
            'dcterms:identifier' => [
                ['type' => 'literal', 'property_id' => 10, '@value' => 'ce:X1', '@language' => 'es'],
                ['type' => 'uri', 'property_id' => 10, '@id' => self::BASE . 'id/ce/X1', 'o:label' => 'ce:X1'],
            ],
            'cur:enunciadoEnCurso' => [[
                'type' => 'literal', 'property_id' => 301, '@value' => 'Statement', '@language' => 'es',
                '@annotation' => ['cur:curso' => [[
                    'type' => 'uri', 'property_id' => 302, '@id' => self::BASE . 'id/curso/S01', 'o:label' => '1º ESO',
                ]]],
            ]],
        ]]], $this->api->callsTo('create', 'items'));
    }

    public function testWritesTheLinksOfNewItemsInTheSecondPass(): void
    {
        $plan = $this->plan(['new' => [
            $this->newItem('etapa:ESO', 'cur:Etapa', ['dcterms:identifier' => [$this->literal('etapa:ESO')]]),
            $this->newItem('ce:X1', 'cur:CompetenciaEspecifica', [
                'dcterms:identifier' => [$this->literal('ce:X1')],
                'cur:etapa' => [$this->link('etapa:ESO')],
                'dcterms:isReplacedBy' => [$this->link('ce:OLD')],
            ]),
        ]]);

        $ids = $this->writer()->apply($plan, $this->structure, ['ce:OLD' => 3]);

        $this->assertSame(['ce:OLD' => 3, 'etapa:ESO' => 1000, 'ce:X1' => 1001], $ids);
        $this->assertSame([[1001, [
            'clear_property_values' => [300, 11],
            'cur:etapa' => [['type' => 'resource:item', 'property_id' => 300, 'value_resource_id' => 1000]],
            'dcterms:isReplacedBy' => [['type' => 'resource:item', 'property_id' => 11, 'value_resource_id' => 3]],
        ], self::APPEND]], $this->api->callsTo('update', 'items'));
    }

    public function testReplacesTheChangedPropertiesOfModifiedItems(): void
    {
        $plan = $this->plan(['modified' => [[
            'identifier' => 'etapa:ESO',
            'item' => 5,
            'class' => 'cur:Etapa',
            'changes' => [
                'skos:prefLabel' => ['before' => [$this->literal('Old')], 'after' => [$this->literal('New')]],
                'skos:scopeNote' => ['before' => [$this->literal('Gone')], 'after' => []],
            ],
        ]]]);

        $this->writer()->apply($plan, $this->structure, ['etapa:ESO' => 5]);

        $this->assertSame([[5, [
            'clear_property_values' => [200, 203],
            'skos:prefLabel' => [['type' => 'literal', 'property_id' => 200, '@value' => 'New', '@language' => 'es']],
        ], self::APPEND]], $this->api->callsTo('update', 'items'));
        $this->assertSame([], $this->api->callsTo('create', 'items'));
    }

    public function testAddsRetiredItemsToTheRetiredItemSet(): void
    {
        $note = 'Retirado en la versión 1.2.0';
        $plan = $this->plan(['retired' => [[
            'identifier' => 'crit:X1',
            'item' => 6,
            'class' => 'cur:CriterioEvaluacion',
            'changes' => ['skos:changeNote' => ['before' => [], 'after' => [$this->literal($note)]]],
        ]]]);

        $this->writer()->apply($plan, $this->structure, ['crit:X1' => 6]);

        $this->assertSame([[6, [
            'clear_property_values' => [204],
            'skos:changeNote' => [['type' => 'literal', 'property_id' => 204, '@value' => $note, '@language' => 'es']],
            'o:item_set' => [['o:id' => 81]],
        ], self::APPEND]], $this->api->callsTo('update', 'items'));
    }

    public function testCreatesRetiredNewItemsInBothItemSets(): void
    {
        $item = $this->newItem('crit:X1', 'cur:CriterioEvaluacion', [
            'dcterms:identifier' => [$this->literal('crit:X1')],
        ]);
        $item['retired'] = true;

        $this->writer()->apply($this->plan(['new' => [$item]]), $this->structure, []);

        $created = $this->api->callsTo('create', 'items')[0][0];
        $this->assertSame([['o:id' => 80], ['o:id' => 81]], $created['o:item_set']);
        $this->assertArrayNotHasKey('o:resource_template', $created);
    }

    public function testWritesInBatchesAndLogsProgress(): void
    {
        $new = [];
        foreach (['etapa:A', 'etapa:B', 'etapa:C'] as $id) {
            $new[] = $this->newItem($id, 'cur:Etapa', [
                'dcterms:identifier' => [$this->literal($id)],
                'cur:etapa' => [$this->link('etapa:A')],
            ]);
        }

        $this->writer(2)->apply($this->plan(['new' => $new]), $this->structure, []);

        $this->assertSame(4, $this->entityManager->clears);
        $this->assertSame([
            'Created 2 of 3 new items.',
            'Created 3 of 3 new items.',
            'Updated 2 of 3 items.',
            'Updated 3 of 3 items.',
        ], $this->log);
    }

    /**
     * Omeka sets the authenticated user as owner of new items; a detached user breaks the next flush.
     */
    public function testKeepsTheAuthenticatedUserManagedAfterEachBatch(): void
    {
        $this->authentication->identity = new class extends User {
            public function getId()
            {
                return 9;
            }
        };
        $new = [$this->newItem('etapa:A', 'cur:Etapa', ['dcterms:identifier' => [$this->literal('etapa:A')]])];

        $this->writer()->apply($this->plan(['new' => $new]), $this->structure, []);

        $this->assertSame([['reference', User::class, 9]], $this->authentication->written);
    }

    public function testRejectsALinkToAnUnknownItem(): void
    {
        $plan = $this->plan(['modified' => [[
            'identifier' => 'etapa:ESO',
            'item' => 5,
            'class' => 'cur:Etapa',
            'changes' => ['cur:etapa' => ['before' => [], 'after' => [$this->link('etapa:XXX')]]],
        ]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No item for the link to etapa:XXX.');

        $this->writer()->apply($plan, $this->structure, ['etapa:ESO' => 5]);
    }

    private function writer(int $batchSize = 100): Writer
    {
        return new Writer($this->api, $this->entityManager, $this->authentication, function (string $message) {
            $this->log[] = $message;
        }, $batchSize);
    }

    private function plan(array $lists): Plan
    {
        return new Plan(
            '1.2.0',
            [],
            $lists['new'] ?? [],
            $lists['modified'] ?? [],
            $lists['retired'] ?? [],
            ['added' => [], 'removed' => []],
            []
        );
    }

    private function newItem(string $identifier, string $class, array $values): array
    {
        return ['identifier' => $identifier, 'class' => $class, 'retired' => false, 'values' => $values];
    }

    private function literal(string $value): array
    {
        return ['type' => 'literal', '@value' => $value, '@language' => 'es'];
    }

    private function link(string $identifier): array
    {
        return ['type' => 'resource:item', 'identifier' => $identifier];
    }
}
