<?php
declare(strict_types=1);

namespace EducationalCurriculum\Release;

/**
 * Reads and validates the files of a curriculum release before anything uses them.
 */
class Reader
{
    /**
     * @param string $jsonPath curriculo.json
     * @param string $checksumPath curriculo.json.sha256, in sha256sum format
     * @param string $vocabularyPath cur.ttl
     * @param string|null $loadedVersion Version already loaded in Omeka, if any
     * @throws ReleaseException
     */
    public function read(
        string $jsonPath,
        string $checksumPath,
        string $vocabularyPath,
        ?string $loadedVersion = null
    ): Release {
        $this->checkChecksum($jsonPath, $checksumPath);

        $data = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            throw new ReleaseException('curriculo.json is not valid JSON.');
        }
        $version = $this->requireString($data, ['corpus', 'version']);
        $uriBase = $this->requireString($data, ['corpus', 'uri_base']);
        $licenseUrl = $this->requireString($data, ['corpus', 'licencia', 'url']);
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new ReleaseException(sprintf('corpus.version "%s" is not a version number like 1.1.2.', $version));
        }
        $nodes = $this->requireNodes($data);
        $this->checkVocabulary($vocabularyPath, $uriBase);
        if ($loadedVersion !== null && version_compare($version, $loadedVersion, '<')) {
            throw new ReleaseException(
                sprintf('Version %s is older than the loaded version %s.', $version, $loadedVersion)
            );
        }

        return new Release($version, $uriBase, $licenseUrl, $nodes, $vocabularyPath);
    }

    private function checkChecksum(string $jsonPath, string $checksumPath): void
    {
        $line = is_readable($checksumPath) ? (string) file_get_contents($checksumPath) : '';
        if (!preg_match('/^([0-9a-f]{64}) [ *]curriculo\.json\s*$/i', $line, $matches)) {
            throw new ReleaseException('The checksum file must contain "<sha256>  curriculo.json".');
        }
        if (!is_readable($jsonPath) || !hash_equals(strtolower($matches[1]), hash_file('sha256', $jsonPath))) {
            throw new ReleaseException('The sha256 checksum does not match curriculo.json.');
        }
    }

    private function requireString(array $data, array $path): string
    {
        $value = $data;
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }
        if (!is_string($value) || $value === '') {
            throw new ReleaseException(sprintf('curriculo.json has no %s.', implode('.', $path)));
        }
        return $value;
    }

    private function requireNodes(array $data): array
    {
        if (!isset($data['nodos'])) {
            throw new ReleaseException('curriculo.json has no nodos.');
        }
        $nodes = $data['nodos'];
        if (!is_array($nodes) || array_values($nodes) !== $nodes) {
            throw new ReleaseException('nodos must be a list.');
        }
        foreach ($nodes as $index => $node) {
            if (!is_array($node) || !is_string($node['id'] ?? null) || !is_string($node['tipo'] ?? null)) {
                throw new ReleaseException(sprintf('Node %d in nodos has no id or tipo.', $index + 1));
            }
        }
        return $nodes;
    }

    private function checkVocabulary(string $vocabularyPath, string $uriBase): void
    {
        $turtle = is_readable($vocabularyPath) ? file_get_contents($vocabularyPath) : false;
        if ($turtle === false || $turtle === '') {
            throw new ReleaseException('cur.ttl cannot be read.');
        }
        $namespace = $uriBase . 'def/cur#';
        if (strpos($turtle, '<' . $namespace . '>') === false) {
            throw new ReleaseException(sprintf('cur.ttl does not declare the namespace %s.', $namespace));
        }
    }
}
