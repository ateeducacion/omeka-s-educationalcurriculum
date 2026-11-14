<?php
declare(strict_types=1);

namespace EducationalCurriculum\Release;

/**
 * A validated curriculum release: corpus metadata and nodes in corpus order.
 */
class Release
{
    private $version;
    private $uriBase;
    private $licenseUrl;
    private $nodes;
    private $vocabularyPath;

    public function __construct(
        string $version,
        string $uriBase,
        string $licenseUrl,
        array $nodes,
        string $vocabularyPath
    ) {
        $this->version = $version;
        $this->uriBase = $uriBase;
        $this->licenseUrl = $licenseUrl;
        $this->nodes = $nodes;
        $this->vocabularyPath = $vocabularyPath;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Base of the published URIs (corpus.uri_base), ending in a slash.
     */
    public function getUriBase(): string
    {
        return $this->uriBase;
    }

    public function getLicenseUrl(): string
    {
        return $this->licenseUrl;
    }

    /**
     * @return array[] Nodes of curriculo.json as decoded arrays, in corpus order.
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * Path of cur.ttl, the vocabulary of the release.
     */
    public function getVocabularyPath(): string
    {
        return $this->vocabularyPath;
    }
}
