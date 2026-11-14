<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

use EducationalCurriculum\Mapping\MappedNode;
use EducationalCurriculum\Mapping\NodeMapper;
use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Release\Release;

/**
 * Analyzes and applies the release stored in the directory of an import.
 *
 * The directory holds the uploaded files (curriculo.json, curriculo.json.sha256 and cur.ttl) and
 * the analysis: informe.json, the full report; resumen.json, what the import page shows; and the
 * lists of the report as JSON Lines, which the page reads one page at a time.
 */
class Synchronizer
{
    const REPORT = 'informe.json';
    const SUMMARY = 'resumen.json';

    /**
     * Lists of the report, one JSON entry per line, so a page of them is read without the rest.
     */
    const LISTS = ['new' => 'nuevos.jsonl', 'modified' => 'modificados.jsonl', 'retired' => 'retirados.jsonl'];

    private $reader;
    private $state;
    private $structureWriter;
    private $writer;
    private $rdfImporter;
    private $planner;

    /**
     * @param \Omeka\Stdlib\RdfImporter $rdfImporter Reads the terms of cur.ttl without installing it
     */
    public function __construct(
        Reader $reader,
        CurrentState $state,
        StructureWriter $structureWriter,
        Writer $writer,
        $rdfImporter,
        Planner $planner
    ) {
        $this->reader = $reader;
        $this->state = $state;
        $this->structureWriter = $structureWriter;
        $this->writer = $writer;
        $this->rdfImporter = $rdfImporter;
        $this->planner = $planner;
    }

    /**
     * Plan the release against the current items and save the report.
     */
    public function analyze(string $directory): Plan
    {
        [, $plan] = $this->plan($directory);
        file_put_contents($directory . '/' . self::REPORT, $plan->toJson());
        file_put_contents($directory . '/' . self::SUMMARY, json_encode([
            'version' => $plan->getVersion(),
            'fingerprint' => $plan->getFingerprint(),
            'empty' => $plan->isEmpty(),
            'counts' => [
                'new' => count($plan->getNew()),
                'modified' => count($plan->getModified()),
                'retired' => count($plan->getRetired()),
            ],
            'summary' => $plan->getSummary(),
            'vocabulary' => $plan->getVocabulary(),
            'errors' => $plan->getErrors(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $lists = ['new' => $plan->getNew(), 'modified' => $plan->getModified(), 'retired' => $plan->getRetired()];
        foreach (self::LISTS as $list => $file) {
            $handle = fopen($directory . '/' . $file, 'w');
            foreach ($lists[$list] as $entry) {
                fwrite($handle, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }
            fclose($handle);
        }
        return $plan;
    }

    /**
     * Plan again and, if the plan is still the approved one, apply it.
     *
     * @param string $fingerprint Fingerprint of the approved report
     * @return bool False, without writing anything, when the plan differs from the report or has errors
     */
    public function apply(string $directory, string $fingerprint): bool
    {
        [$release, $plan, $desired, $current] = $this->plan($directory);
        if ($plan->hasErrors() || !hash_equals($fingerprint, $plan->getFingerprint())) {
            return false;
        }

        $this->structureWriter->ensureVocabulary($release);
        [$classProperties, $annotationProperties] = $this->propertiesInUse($desired);
        $structure = $this->structureWriter->prepare($release, $classProperties, $annotationProperties);
        $itemIds = [];
        foreach ($current as $item) {
            $itemIds[$item->getIdentifier()] = $item->getId();
        }
        $this->writer->apply($plan, $structure, $itemIds);
        $this->structureWriter->setVersion($structure, $release->getVersion());
        return true;
    }

    /**
     * @return array [Release, Plan, MappedNode[], CurrentItem[]]
     */
    private function plan(string $directory): array
    {
        $release = $this->reader->read(
            $directory . '/curriculo.json',
            $directory . '/curriculo.json.sha256',
            $directory . '/cur.ttl'
        );
        $desired = (new NodeMapper($release))->mapAll();
        $namespace = $release->getUriBase() . 'def/cur#';
        $sets = $this->structureWriter->findItemSets($release->getUriBase());
        $current = $sets['curriculum'] === null ? [] : $this->state->read($sets['curriculum'], $sets['retired']);
        $plan = $this->planner->plan(
            $release->getVersion(),
            $desired,
            $current,
            $this->state->vocabularyTerms($namespace, StructureWriter::VOCABULARY_PREFIX),
            $this->releaseTerms($release, $namespace)
        );
        return [$release, $plan, $desired, $current];
    }

    /**
     * Terms of the cur.ttl of the release, classes first.
     */
    private function releaseTerms(Release $release, string $namespace): array
    {
        $members = $this->rdfImporter->getMembers('file', $namespace, [
            'file' => $release->getVocabularyPath(),
            'format' => 'turtle',
        ]);
        $terms = [];
        foreach (array_merge(array_keys($members['classes']), array_keys($members['properties'])) as $name) {
            $terms[] = StructureWriter::VOCABULARY_PREFIX . ':' . $name;
        }
        return $terms;
    }

    /**
     * Properties each class uses, in mapping order, and the properties of value annotations.
     *
     * @param MappedNode[] $desired
     */
    private function propertiesInUse(array $desired): array
    {
        $used = [];
        $annotations = [];
        foreach ($desired as $node) {
            foreach ($node->getValues() as $term => $values) {
                $used[$node->getResourceClass()][$term] = true;
                foreach ($values as $value) {
                    foreach (array_keys($value['@annotation'] ?? []) as $annotationTerm) {
                        $annotations[$annotationTerm] = true;
                    }
                }
            }
        }
        $classProperties = [];
        foreach ($used as $class => $terms) {
            $classProperties[$class] = array_values(array_filter(NodeMapper::PROPERTIES, function ($term) use ($terms) {
                return isset($terms[$term]);
            }));
        }
        return [$classProperties, array_keys($annotations)];
    }
}
