<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Sync;

use EducationalCurriculum\Mapping\MappedNode;
use EducationalCurriculum\Mapping\NodeMapper;
use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Sync\CurrentItem;
use EducationalCurriculum\Sync\Plan;
use EducationalCurriculum\Sync\Planner;
use PHPUnit\Framework\TestCase;

class PlannerTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/release-1.1.2';
    private const VOCABULARY = ['cur:Etapa', 'cur:Curso', 'cur:CriterioEvaluacion', 'cur:etapa', 'cur:curso'];

    private $planner;

    protected function setUp(): void
    {
        $this->planner = new Planner();
    }

    public function testPlansEveryNodeAsNewOnAnEmptyInstance(): void
    {
        $plan = $this->planner->plan('1.1.2', [$this->stage(), $this->course()], [], [], self::VOCABULARY);

        $this->assertSame([
            ['identifier' => 'etapa:ESO', 'class' => 'cur:Etapa', 'retired' => false,
                'values' => $this->stage()->getValues()],
            ['identifier' => 'curso:S01', 'class' => 'cur:Curso', 'retired' => false,
                'values' => $this->course()->getValues()],
        ], $plan->getNew());
        $this->assertSame([], $plan->getModified());
        $this->assertSame([], $plan->getRetired());
        $this->assertSame(['added' => self::VOCABULARY, 'removed' => []], $plan->getVocabulary());
        $this->assertFalse($plan->isEmpty());
        $this->assertFalse($plan->hasErrors());
    }

    public function testPlansNothingWhenTheSameReleaseIsAppliedAgain(): void
    {
        $plan = $this->planSame([$this->stage(), $this->course()], [
            $this->current(1, $this->stage()),
            $this->current(2, $this->course()),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([], $plan->getNew());
        $this->assertSame([], $plan->getModified());
        $this->assertSame(['added' => [], 'removed' => []], $plan->getVocabulary());
        $this->assertSame([
            'cur:Curso' => ['new' => 0, 'modified' => 0, 'retired' => 0, 'unchanged' => 1],
            'cur:Etapa' => ['new' => 0, 'modified' => 0, 'retired' => 0, 'unchanged' => 1],
        ], $plan->getSummary());
    }

    public function testIgnoresTheKeyOrderInsideAValue(): void
    {
        $values = $this->stage()->getValues();
        $values['skos:prefLabel'] = [
            ['@language' => 'es', '@value' => 'Educación Secundaria Obligatoria', 'type' => 'literal'],
        ];

        $plan = $this->planSame([$this->stage()], [new CurrentItem(1, 'cur:Etapa', $values, false)]);

        $this->assertTrue($plan->isEmpty());
    }

    public function testReplacesOnlyThePropertiesThatChange(): void
    {
        $plan = $this->planSame([$this->course()], [$this->current(7, $this->course('1º ESO (old)'))]);

        $this->assertSame([[
            'identifier' => 'curso:S01',
            'item' => 7,
            'class' => 'cur:Curso',
            'changes' => ['skos:prefLabel' => [
                'before' => [$this->literal('1º ESO (old)')],
                'after' => [$this->literal('1º ESO')],
            ]],
        ]], $plan->getModified());
        $this->assertSame(
            ['new' => 0, 'modified' => 1, 'retired' => 0, 'unchanged' => 0],
            $plan->getSummary()['cur:Curso']
        );
    }

    public function testAChangeOfOrderAloneIsAChange(): void
    {
        $s01 = $this->link('curso:S01');
        $s02 = $this->link('curso:S02');
        $desired = $this->node('materia:SMAT', 'Materia', ['cur:curso' => [$s01, $s02]]);
        $current = $this->node('materia:SMAT', 'Materia', ['cur:curso' => [$s02, $s01]]);

        $plan = $this->planner->plan('1.1.2', [$desired], [$this->current(3, $current)], [], []);

        $this->assertSame(['cur:curso'], array_keys($plan->getModified()[0]['changes']));
    }

    public function testRemovesValuesOfAPropertyTheReleaseNoLongerHas(): void
    {
        $desired = $this->node('bc:X1', 'BloqueCompetencial', []);
        $current = $this->node('bc:X1', 'BloqueCompetencial', ['skos:scopeNote' => [$this->literal('Old note')]]);

        $plan = $this->planner->plan('1.1.2', [$desired], [$this->current(4, $current)], [], []);

        $this->assertSame(
            ['skos:scopeNote' => ['before' => [$this->literal('Old note')], 'after' => []]],
            $plan->getModified()[0]['changes']
        );
    }

    public function testListsNodesRetiredInThisRelease(): void
    {
        $note = $this->literal('Retirado en la versión 1.2.0');
        $desired = $this->node('crit:X1', 'CriterioEvaluacion', [
            'skos:changeNote' => [$note],
            'dcterms:isReplacedBy' => [$this->link('crit:X2')],
        ], true);
        $replacement = $this->node('crit:X2', 'CriterioEvaluacion', []);
        $current = [
            $this->current(5, $this->node('crit:X1', 'CriterioEvaluacion', [])),
            $this->current(6, $replacement),
        ];

        $plan = $this->planSame([$desired, $replacement], $current, '1.2.0');

        $this->assertSame([], $plan->getModified());
        $this->assertSame([[
            'identifier' => 'crit:X1',
            'item' => 5,
            'class' => 'cur:CriterioEvaluacion',
            'changes' => [
                'skos:changeNote' => ['before' => [], 'after' => [$note]],
                'dcterms:isReplacedBy' => ['before' => [], 'after' => [$this->link('crit:X2')]],
            ],
        ]], $plan->getRetired());
    }

    public function testKeepsTheRetirementNoteOfAnItemRetiredEarlier(): void
    {
        $desired = $this->node('crit:X1', 'CriterioEvaluacion', [
            'skos:changeNote' => [$this->literal('Retirado en la versión 1.3.0')],
        ], true);
        $current = $this->node('crit:X1', 'CriterioEvaluacion', [
            'skos:changeNote' => [$this->literal('Retirado en la versión 1.2.0')],
        ], true);

        $plan = $this->planSame([$desired], [$this->current(5, $current)], '1.3.0');

        $this->assertTrue($plan->isEmpty());
    }

    public function testListsRemovedVocabularyTermsThatNoItemUses(): void
    {
        $plan = $this->planner->plan('1.1.2', [], [], ['cur:Etapa', 'cur:obsoleteTerm'], ['cur:Etapa']);

        $this->assertSame(['added' => [], 'removed' => ['cur:obsoleteTerm']], $plan->getVocabulary());
        $this->assertFalse($plan->hasErrors());
        $this->assertFalse($plan->isEmpty());
    }

    public function testReportsAnItemWhoseIdentifierIsNotInTheRelease(): void
    {
        $plan = $this->planSame([], [$this->current(9, $this->stage())]);

        $this->assertSame([$this->error(Plan::ERROR_UNKNOWN_IDENTIFIER, 'etapa:ESO', 9)], $plan->getErrors());
        $this->assertTrue($plan->hasErrors());
    }

    public function testReportsAnItemWhoseClassDoesNotMatchTheNodeType(): void
    {
        $current = new CurrentItem(9, 'cur:Materia', $this->stage()->getValues(), false);

        $plan = $this->planSame([$this->stage()], [$current]);

        $this->assertSame([$this->error(Plan::ERROR_CLASS_MISMATCH, 'etapa:ESO', 9, [
            'current' => 'cur:Materia',
            'expected' => 'cur:Etapa',
        ])], $plan->getErrors());
        $this->assertSame([], $plan->getModified());
    }

    public function testReportsAnItemRetiredInOmekaThatIsCurrentAgain(): void
    {
        $current = new CurrentItem(9, 'cur:Etapa', $this->stage()->getValues(), true);

        $plan = $this->planSame([$this->stage()], [$current]);

        $this->assertSame([$this->error(Plan::ERROR_UNRETIRED, 'etapa:ESO', 9)], $plan->getErrors());
    }

    public function testReportsTwoItemsWithTheSameIdentifier(): void
    {
        $plan = $this->planSame([$this->stage()], [
            $this->current(9, $this->stage()),
            $this->current(10, $this->stage()),
        ]);

        $this->assertSame([
            $this->error(Plan::ERROR_DUPLICATE_IDENTIFIER, 'etapa:ESO', 9),
            $this->error(Plan::ERROR_DUPLICATE_IDENTIFIER, 'etapa:ESO', 10),
        ], $plan->getErrors());
        $this->assertSame([], $plan->getNew());
    }

    public function testReportsAnItemWithoutLiteralIdentifier(): void
    {
        $values = ['skos:prefLabel' => [$this->literal('Sin identificador')]];

        $plan = $this->planSame([], [new CurrentItem(9, 'cur:Etapa', $values, false)]);

        $this->assertSame([$this->error(Plan::ERROR_MISSING_IDENTIFIER, null, 9)], $plan->getErrors());
    }

    public function testReportsALinkToANodeThatIsNotInTheRelease(): void
    {
        $desired = $this->node('curso:S01', 'Curso', ['cur:etapa' => [$this->link('etapa:XXX')]]);

        $plan = $this->planSame([$desired], []);

        $this->assertSame([$this->error(Plan::ERROR_UNKNOWN_LINK, 'curso:S01', null, [
            'property' => 'cur:etapa',
            'target' => 'etapa:XXX',
        ])], $plan->getErrors());
    }

    public function testReportsARemovedVocabularyTermThatItemsUse(): void
    {
        $plan = $this->planner->plan(
            '1.1.2',
            [$this->stage(), $this->course()],
            [$this->current(1, $this->stage()), $this->current(2, $this->course())],
            self::VOCABULARY,
            ['cur:Etapa', 'cur:Curso', 'cur:CriterioEvaluacion', 'cur:curso']
        );

        $this->assertSame(
            [$this->error(Plan::ERROR_VOCABULARY_TERM_REMOVED, null, null, ['term' => 'cur:etapa'])],
            $plan->getErrors()
        );
    }

    public function testReportsATermTheReleaseNeedsThatTheVocabularyLacks(): void
    {
        $plan = $this->planner->plan('1.1.2', [$this->stage(), $this->course()], [], [], ['cur:Etapa', 'cur:Curso']);

        $this->assertSame(
            [$this->error(Plan::ERROR_VOCABULARY_TERM_MISSING, null, null, ['term' => 'cur:etapa'])],
            $plan->getErrors()
        );
    }

    public function testSerializesTheReportAndItsFingerprint(): void
    {
        $plan = $this->planner->plan('1.1.2', [$this->stage()], [], [], self::VOCABULARY);
        $same = $this->planner->plan('1.1.2', [$this->stage()], [], [], self::VOCABULARY);
        $other = $this->planner->plan('1.1.2', [$this->course()], [], [], self::VOCABULARY);

        $report = json_decode($plan->toJson(), true);
        $this->assertSame(
            ['version', 'summary', 'new', 'modified', 'retired', 'vocabulary', 'errors'],
            array_keys($report)
        );
        $this->assertSame('1.1.2', $report['version']);
        $this->assertStringContainsString('Educación Secundaria Obligatoria', $plan->toJson());
        $this->assertSame(hash('sha256', $plan->toJson()), $plan->getFingerprint());
        $this->assertSame($plan->getFingerprint(), $same->getFingerprint());
        $this->assertNotSame($plan->getFingerprint(), $other->getFingerprint());
    }

    public function testPlansTheFixtureReleaseOnAnEmptyInstance(): void
    {
        $release = (new Reader())->read(
            self::FIXTURE . '/curriculo.json',
            self::FIXTURE . '/curriculo.json.sha256',
            self::FIXTURE . '/cur.ttl'
        );
        $desired = (new NodeMapper($release))->mapAll();
        $terms = [];
        foreach ($desired as $node) {
            $terms = array_merge($terms, [$node->getResourceClass()], array_keys($node->getValues()));
        }
        $curTerms = array_values(array_unique(array_filter($terms, function ($term) {
            return strpos($term, 'cur:') === 0;
        })));

        $plan = $this->planner->plan('1.1.2', $desired, [], [], $curTerms);

        $this->assertFalse($plan->hasErrors());
        $this->assertCount(125, $plan->getNew());
        $this->assertSame(82, $plan->getSummary()['cur:SaberBasico']['new']);
    }

    /**
     * Plan with the same cur vocabulary installed and in the release.
     */
    private function planSame(array $desired, array $current, string $version = '1.1.2'): Plan
    {
        return $this->planner->plan($version, $desired, $current, self::VOCABULARY, self::VOCABULARY);
    }

    private function stage(): MappedNode
    {
        return $this->node('etapa:ESO', 'Etapa', [
            'skos:prefLabel' => [$this->literal('Educación Secundaria Obligatoria')],
        ]);
    }

    private function course(string $label = '1º ESO'): MappedNode
    {
        return $this->node('curso:S01', 'Curso', [
            'skos:prefLabel' => [$this->literal($label)],
            'cur:etapa' => [$this->link('etapa:ESO')],
        ]);
    }

    private function node(string $id, string $type, array $values, bool $retired = false): MappedNode
    {
        $identifier = ['dcterms:identifier' => [$this->literal($id)]];
        return new MappedNode($id, 'cur:' . $type, $identifier + $values, $retired);
    }

    private function current(int $itemId, MappedNode $node): CurrentItem
    {
        return new CurrentItem($itemId, $node->getResourceClass(), $node->getValues(), $node->isRetired());
    }

    private function literal(string $value): array
    {
        return ['type' => 'literal', '@value' => $value, '@language' => 'es'];
    }

    private function link(string $identifier): array
    {
        return ['type' => 'resource:item', 'identifier' => $identifier];
    }

    private function error(string $code, ?string $identifier, ?int $item, array $detail = []): array
    {
        return ['code' => $code, 'identifier' => $identifier, 'item' => $item, 'detail' => $detail];
    }
}
