<?php
declare(strict_types=1);

namespace EducationalCurriculum\Mapping;

use EducationalCurriculum\Release\Release;

/**
 * Maps the nodes of a release to items following the contract of the curriculum repository
 * (docs/omeka.md): one resource class cur:<tipo> and the values of the module properties.
 */
class NodeMapper
{
    const LANGUAGE = 'es';

    const TYPES = [
        'Etapa',
        'Curso',
        'Materia',
        'MateriaCurso',
        'CompetenciaEspecifica',
        'BloqueCompetencial',
        'CriterioEvaluacion',
        'BloqueSaberes',
        'SaberBasico',
    ];

    /**
     * Properties owned by the module, in the order values are produced.
     */
    const PROPERTIES = [
        'dcterms:identifier',
        'skos:notation',
        'skos:prefLabel',
        'skos:definition',
        'skos:scopeNote',
        'cur:enunciadoEnCurso',
        'skos:broader',
        'cur:agrupa',
        'cur:bloqueCompetencial',
        'cur:descriptorOperativo',
        'cur:etapa',
        'cur:curso',
        'cur:materia',
        'cur:materiaCurso',
        'skos:changeNote',
        'dcterms:isReplacedBy',
    ];

    /**
     * Literal fields of a node and their property.
     */
    const LITERALS = [
        'notation' => 'skos:notation',
        'prefLabel' => 'skos:prefLabel',
        'definition' => 'skos:definition',
        'scopeNote' => 'skos:scopeNote',
    ];

    /**
     * Link fields of a node (one id or a list of ids) and their property.
     */
    const LINKS = [
        'broader' => 'skos:broader',
        'agrupa' => 'cur:agrupa',
        'bloque' => 'cur:bloqueCompetencial',
        'etapa' => 'cur:etapa',
        'curso' => 'cur:curso',
        'cursos' => 'cur:curso',
        'materia' => 'cur:materia',
        'materia_curso' => 'cur:materiaCurso',
        'sustituido_por' => 'dcterms:isReplacedBy',
    ];

    private $release;
    private $nodes = [];

    public function __construct(Release $release)
    {
        $this->release = $release;
        foreach ($release->getNodes() as $node) {
            $this->nodes[$node['id']] = $node;
        }
    }

    /**
     * @return MappedNode[] One per node, in corpus order.
     */
    public function mapAll(): array
    {
        return array_map([$this, 'map'], $this->release->getNodes());
    }

    /**
     * @throws MappingException
     */
    public function map(array $node): MappedNode
    {
        $id = $node['id'];
        if (!in_array($node['tipo'], self::TYPES, true)) {
            throw new MappingException(sprintf('Node %s has an unknown tipo "%s".', $id, $node['tipo']));
        }

        $values = [];
        $values['dcterms:identifier'] = [$this->literal($id), $this->uri($this->nodeUri($id), $id)];
        foreach (self::LITERALS as $field => $term) {
            if (isset($node[$field]) && $node[$field] !== '') {
                $values[$term][] = $this->literal($node[$field]);
            }
        }
        foreach ($node['enunciados_curso'] ?? [] as $courseId => $statement) {
            $values['cur:enunciadoEnCurso'][] = $this->literal($statement) + [
                '@annotation' => ['cur:curso' => [$this->courseUri($id, $courseId)]],
            ];
        }
        foreach (self::LINKS as $field => $term) {
            foreach ((array) ($node[$field] ?? []) as $target) {
                $values[$term][] = ['type' => 'resource:item', 'identifier' => $target];
            }
        }
        foreach ($node['descriptores'] ?? [] as $code) {
            $values['cur:descriptorOperativo'][] = $this->uri(
                $this->release->getUriBase() . 'id/descriptor/' . $this->stageNotation($node) . '/' . $code,
                $code
            );
        }
        $retired = !empty($node['obsoleto']);
        if ($retired) {
            $values['skos:changeNote'] = [
                $this->literal(sprintf('Retirado en la versión %s', $this->release->getVersion())),
            ];
        }

        return new MappedNode($id, 'cur:' . $node['tipo'], $this->sortByProperty($values), $retired);
    }

    /**
     * Published URI of a node: <prefix>:<code> is <base>id/<prefix>/<code>.
     */
    private function nodeUri(string $id): string
    {
        $parts = explode(':', $id, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new MappingException(sprintf('Node %s has an id that is not <prefix>:<code>.', $id));
        }
        return $this->release->getUriBase() . 'id/' . $parts[0] . '/' . $parts[1];
    }

    private function courseUri(string $id, string $courseId): array
    {
        if (!isset($this->nodes[$courseId])) {
            throw new MappingException(
                sprintf('Node %s has a statement for %s, which is not in the release.', $id, $courseId)
            );
        }
        return $this->uri($this->nodeUri($courseId), $this->nodes[$courseId]['prefLabel']);
    }

    private function stageNotation(array $node): string
    {
        $stage = $node['etapa'] ?? '';
        if (!isset($this->nodes[$stage])) {
            throw new MappingException(sprintf(
                'Node %s has descriptores but its etapa %s is not in the release.',
                $node['id'],
                $stage
            ));
        }
        return $this->nodes[$stage]['notation'];
    }

    private function sortByProperty(array $values): array
    {
        $sorted = [];
        foreach (self::PROPERTIES as $term) {
            if (isset($values[$term])) {
                $sorted[$term] = $values[$term];
            }
        }
        return $sorted;
    }

    private function literal(string $value): array
    {
        return ['type' => 'literal', '@value' => $value, '@language' => self::LANGUAGE];
    }

    private function uri(string $uri, string $label): array
    {
        return ['type' => 'uri', '@id' => $uri, 'o:label' => $label];
    }
}
