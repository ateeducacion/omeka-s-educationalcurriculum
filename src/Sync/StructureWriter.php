<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

use EducationalCurriculum\Release\Release;

/**
 * Creates or updates what the curriculum items need before they are written: the cur vocabulary,
 * one resource template per class and the two item sets.
 */
class StructureWriter
{
    const VOCABULARY_PREFIX = 'cur';
    const CURRICULUM_LABEL = 'Currículo LOMLOE de Canarias';
    const RETIRED_LABEL = 'Currículo · Retirados';
    const TEMPLATE_PREFIX = 'Currículo · ';
    const CURRICULUM_PATH = 'id/curriculo';
    const RETIRED_PATH = 'id/curriculo/retirados';
    const LANGUAGE = 'es';

    /**
     * Partial update that replaces the values of the cleared properties and keeps the rest.
     */
    const APPEND = ['isPartial' => true, 'collectionAction' => 'append'];

    private $api;
    private $rdfImporter;
    private $propertyIds = [];
    private $classIds = [];
    private $classLabels = [];

    /**
     * @param \Omeka\Api\Manager $api
     * @param \Omeka\Stdlib\RdfImporter $rdfImporter
     */
    public function __construct($api, $rdfImporter)
    {
        $this->api = $api;
        $this->rdfImporter = $rdfImporter;
    }

    /**
     * Import cur.ttl as the cur vocabulary, or add its new terms and labels to the installed one.
     */
    public function ensureVocabulary(Release $release): void
    {
        $namespace = $release->getUriBase() . 'def/cur#';
        $options = ['file' => $release->getVocabularyPath(), 'format' => 'turtle', 'lang' => self::LANGUAGE];
        $installed = $this->api->search('vocabularies', ['namespace_uri' => $namespace])->getContent();
        if ($installed) {
            $diff = $this->rdfImporter->getDiff('file', $namespace, $options);
            $this->rdfImporter->update($installed[0]->id(), $diff);
            return;
        }
        $this->rdfImporter->import('file', [
            'o:namespace_uri' => $namespace,
            'o:prefix' => self::VOCABULARY_PREFIX,
            'o:label' => self::CURRICULUM_LABEL,
        ], $options);
    }

    /**
     * @param array $classProperties Properties used by each class: [class term => [property terms]]
     * @param string[] $extraProperties Other properties the items use, such as annotation properties
     * @throws StructureException
     */
    public function prepare(Release $release, array $classProperties, array $extraProperties = []): Structure
    {
        foreach (['dcterms:title', 'dcterms:identifier', 'dcterms:license', 'dcterms:hasVersion',
            'skos:prefLabel', 'skos:definition'] as $term) {
            $this->propertyId($term);
        }
        foreach ($extraProperties as $term) {
            $this->propertyId($term);
        }

        $templateIds = [];
        foreach ($classProperties as $class => $properties) {
            $templateIds[$class] = $this->ensureTemplate($class, $properties);
        }

        $base = $release->getUriBase();
        $sets = $this->findItemSets($base);
        $curriculum = $this->ensureItemSet($sets['curriculum'], [
            'o:resource_class' => ['o:id' => $this->classId('skos:ConceptScheme')],
            'o:is_public' => true,
            'dcterms:title' => [$this->literal('dcterms:title', self::CURRICULUM_LABEL)],
            'skos:prefLabel' => [$this->literal('skos:prefLabel', self::CURRICULUM_LABEL)],
            'dcterms:identifier' => [$this->uri('dcterms:identifier', $base . self::CURRICULUM_PATH)],
            'dcterms:license' => [$this->uri('dcterms:license', $release->getLicenseUrl())],
        ]);
        $retired = $this->ensureItemSet($sets['retired'], [
            'o:is_public' => true,
            'dcterms:title' => [$this->literal('dcterms:title', self::RETIRED_LABEL)],
            'dcterms:identifier' => [$this->uri('dcterms:identifier', $base . self::RETIRED_PATH)],
        ]);

        return new Structure($this->propertyIds, $this->classIds, $templateIds, $curriculum, $retired);
    }

    /**
     * Ids of the curriculum item set and the item set of retired terms, located by dcterms:identifier.
     *
     * @return array ['curriculum' => ?int, 'retired' => ?int]
     */
    public function findItemSets(string $uriBase): array
    {
        return [
            'curriculum' => $this->findItemSet($uriBase . self::CURRICULUM_PATH),
            'retired' => $this->findItemSet($uriBase . self::RETIRED_PATH),
        ];
    }

    /**
     * Record the loaded version on the curriculum item set: the last step of applying a release.
     */
    public function setVersion(Structure $structure, string $version): void
    {
        $propertyId = $this->propertyId('dcterms:hasVersion');
        $this->api->update('item_sets', $structure->getCurriculumItemSetId(), [
            'clear_property_values' => [$propertyId],
            'dcterms:hasVersion' => [['type' => 'literal', 'property_id' => $propertyId, '@value' => $version]],
        ], [], self::APPEND);
    }

    /**
     * Create the template of a class unless it exists; an existing one keeps any local changes.
     */
    private function ensureTemplate(string $class, array $properties): int
    {
        $classId = $this->classId($class);
        $propertyIds = array_map([$this, 'propertyId'], $properties);
        $label = self::TEMPLATE_PREFIX . $this->classLabels[$class];
        $existing = $this->api->search('resource_templates', ['label' => $label])->getContent();
        if ($existing) {
            return $existing[0]->id();
        }
        return $this->api->create('resource_templates', [
            'o:label' => $label,
            'o:resource_class' => ['o:id' => $classId],
            'o:title_property' => ['o:id' => $this->propertyId('skos:prefLabel')],
            'o:description_property' => ['o:id' => $this->propertyId('skos:definition')],
            'o:resource_template_property' => array_map(function ($id) {
                return ['o:property' => ['o:id' => $id]];
            }, $propertyIds),
        ])->getContent()->id();
    }

    private function ensureItemSet(?int $id, array $data): int
    {
        if ($id === null) {
            return $this->api->create('item_sets', $data)->getContent()->id();
        }
        $owned = [];
        foreach ($data as $key => $values) {
            if (strpos($key, 'o:') !== 0) {
                $owned[] = $this->propertyId($key);
            }
        }
        $this->api->update('item_sets', $id, ['clear_property_values' => $owned] + $data, [], self::APPEND);
        return $id;
    }

    private function findItemSet(string $uri): ?int
    {
        $found = $this->api->search('item_sets', ['property' => [
            ['property' => 'dcterms:identifier', 'type' => 'eq', 'text' => $uri],
        ]])->getContent();
        return $found ? $found[0]->id() : null;
    }

    private function classId(string $term): int
    {
        if (!isset($this->classIds[$term])) {
            $found = $this->api->search('resource_classes', ['term' => $term])->getContent();
            if (!$found) {
                throw new StructureException(sprintf('The resource class %s is not installed.', $term));
            }
            $this->classIds[$term] = $found[0]->id();
            $this->classLabels[$term] = $found[0]->label();
        }
        return $this->classIds[$term];
    }

    private function propertyId(string $term): int
    {
        if (!isset($this->propertyIds[$term])) {
            $found = $this->api->search('properties', ['term' => $term])->getContent();
            if (!$found) {
                throw new StructureException(sprintf('The property %s is not installed.', $term));
            }
            $this->propertyIds[$term] = $found[0]->id();
        }
        return $this->propertyIds[$term];
    }

    private function literal(string $term, string $value): array
    {
        return [
            'type' => 'literal',
            'property_id' => $this->propertyId($term),
            '@value' => $value,
            '@language' => self::LANGUAGE,
        ];
    }

    private function uri(string $term, string $uri): array
    {
        return ['type' => 'uri', 'property_id' => $this->propertyId($term), '@id' => $uri];
    }
}
