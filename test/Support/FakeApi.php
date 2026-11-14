<?php
declare(strict_types=1);

namespace EducationalCurriculumTest\Support;

/**
 * Records the calls to the Omeka API manager and answers searches with registered resources.
 */
class FakeApi
{
    public $calls = [];
    private $found = [];
    private $totals = [];
    private $read = [];
    private $nextId = 1000;

    /**
     * Register a property or resource class, found when searching by term.
     */
    public function withTerm(string $resource, string $term, int $id, string $label = ''): self
    {
        return $this->willFind($resource, ['term' => $term], [new FakeResource($id, $label)]);
    }

    public function willFind(string $resource, array $query, array $content): self
    {
        $this->found[$this->key($resource, $query)] = $content;
        return $this;
    }

    /**
     * Register the total of a search, as with limit 0.
     */
    public function willCount(string $resource, array $query, int $total): self
    {
        $this->totals[$this->key($resource, $query)] = $total;
        return $this;
    }

    public function willRead(string $resource, int $id, FakeResource $content): self
    {
        $this->read[$resource][$id] = $content;
        return $this;
    }

    public function search($resource, array $query = [], array $options = [])
    {
        $this->calls[] = ['search', $resource, $query];
        $key = $this->key($resource, $query);
        return new FakeResponse($this->found[$key] ?? [], $this->totals[$key] ?? null);
    }

    public function read($resource, $id, array $data = [], array $options = [])
    {
        $this->calls[] = ['read', $resource, $id];
        return new FakeResponse($this->read[$resource][$id]);
    }

    public function create($resource, array $data = [], $fileData = [], array $options = [])
    {
        $this->calls[] = ['create', $resource, $data];
        return new FakeResponse(new FakeResource($this->nextId++));
    }

    public function update($resource, $id, array $data = [], $fileData = [], array $options = [])
    {
        $this->calls[] = ['update', $resource, $id, $data, $options];
        return new FakeResponse(new FakeResource((int) $id));
    }

    /**
     * Calls of one method on one resource, without the method and resource names.
     */
    public function callsTo(string $method, string $resource): array
    {
        $calls = [];
        foreach ($this->calls as $call) {
            if ($call[0] === $method && $call[1] === $resource) {
                $calls[] = array_slice($call, 2);
            }
        }
        return $calls;
    }

    private function key(string $resource, array $query): string
    {
        return $resource . json_encode($query);
    }
}
