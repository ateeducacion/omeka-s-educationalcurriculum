<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Release;

use EducationalCurriculum\Release\Reader;
use EducationalCurriculum\Release\Release;
use EducationalCurriculum\Release\ReleaseException;
use PHPUnit\Framework\TestCase;

class ReaderTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/release-1.1.2';

    private $dir;
    private $reader;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/educationalcurriculum-reader-' . uniqid();
        mkdir($this->dir);
        foreach (['curriculo.json', 'curriculo.json.sha256', 'cur.ttl'] as $file) {
            copy(self::FIXTURE . '/' . $file, $this->dir . '/' . $file);
        }
        $this->reader = new Reader();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testReadsThePublishedRelease(): void
    {
        $release = $this->read();

        $this->assertInstanceOf(Release::class, $release);
        $this->assertSame('1.1.2', $release->getVersion());
        $this->assertSame('https://ateeducacion.github.io/curriculo_lomloe_canarias/', $release->getUriBase());
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $release->getLicenseUrl());
        $this->assertSame($this->dir . '/cur.ttl', $release->getVocabularyPath());
    }

    public function testKeepsTheNodesInCorpusOrder(): void
    {
        $nodes = $this->read()->getNodes();

        $this->assertCount(125, $nodes);
        $this->assertSame('etapa:ESO', $nodes[0]['id']);
        $this->assertSame(array_column($this->fixtureData()['nodos'], 'id'), array_column($nodes, 'id'));
    }

    public function testAcceptsTheBinaryModeChecksumLine(): void
    {
        $this->writeChecksum(hash_file('sha256', $this->dir . '/curriculo.json') . " *curriculo.json\n");

        $this->assertSame('1.1.2', $this->read()->getVersion());
    }

    public function testRejectsAChecksumThatDoesNotMatch(): void
    {
        $this->writeChecksum(str_repeat('0', 64) . "  curriculo.json\n");

        $this->expectReleaseError('The sha256 checksum does not match curriculo.json.');
        $this->read();
    }

    /**
     * @dataProvider malformedChecksums
     */
    public function testRejectsAMalformedChecksumFile(string $content): void
    {
        $this->writeChecksum($content);

        $this->expectReleaseError('The checksum file must contain "<sha256>  curriculo.json".');
        $this->read();
    }

    public function malformedChecksums(): array
    {
        $hash = hash_file('sha256', self::FIXTURE . '/curriculo.json');
        return [
            'empty' => [''],
            'hash only' => [$hash . "\n"],
            'other file name' => [$hash . "  curriculo.ttl\n"],
            'short hash' => [substr($hash, 1) . "  curriculo.json\n"],
        ];
    }

    public function testRejectsInvalidJson(): void
    {
        $this->writeJson('{"corpus": ');

        $this->expectReleaseError('curriculo.json is not valid JSON.');
        $this->read();
    }

    /**
     * @dataProvider requiredFields
     */
    public function testRejectsJsonWithoutARequiredField(callable $remove, string $field): void
    {
        $data = $this->fixtureData();
        $remove($data);
        $this->writeJson(json_encode($data));

        $this->expectReleaseError(sprintf('curriculo.json has no %s.', $field));
        $this->read();
    }

    public function requiredFields(): array
    {
        return [
            'no corpus' => [function (array &$data) {
                unset($data['corpus']);
            }, 'corpus.version'],
            'no version' => [function (array &$data) {
                unset($data['corpus']['version']);
            }, 'corpus.version'],
            'empty version' => [function (array &$data) {
                $data['corpus']['version'] = '';
            }, 'corpus.version'],
            'no uri_base' => [function (array &$data) {
                unset($data['corpus']['uri_base']);
            }, 'corpus.uri_base'],
            'no license url' => [function (array &$data) {
                unset($data['corpus']['licencia']['url']);
            }, 'corpus.licencia.url'],
            'no nodos' => [function (array &$data) {
                unset($data['nodos']);
            }, 'nodos'],
        ];
    }

    public function testRejectsAVersionThatIsNotSemantic(): void
    {
        $data = $this->fixtureData();
        $data['corpus']['version'] = 'v1.1';
        $this->writeJson(json_encode($data));

        $this->expectReleaseError('corpus.version "v1.1" is not a version number like 1.1.2.');
        $this->read();
    }

    /**
     * @dataProvider malformedNodes
     */
    public function testRejectsMalformedNodes(array $nodes, string $message): void
    {
        $data = $this->fixtureData();
        $data['nodos'] = $nodes;
        $this->writeJson(json_encode($data));

        $this->expectReleaseError($message);
        $this->read();
    }

    public function malformedNodes(): array
    {
        return [
            'object instead of list' => [['a' => ['id' => 'etapa:ESO', 'tipo' => 'Etapa']], 'nodos must be a list.'],
            'node is not an object' => [['etapa:ESO'], 'Node 1 in nodos has no id or tipo.'],
            'node without id' => [[['tipo' => 'Etapa']], 'Node 1 in nodos has no id or tipo.'],
            'second node without tipo' => [
                [['id' => 'etapa:ESO', 'tipo' => 'Etapa'], ['id' => 'curso:S01']],
                'Node 2 in nodos has no id or tipo.',
            ],
        ];
    }

    public function testRejectsAMissingVocabulary(): void
    {
        unlink($this->dir . '/cur.ttl');

        $this->expectReleaseError('cur.ttl cannot be read.');
        $this->read();
    }

    public function testRejectsAVocabularyForAnotherUriBase(): void
    {
        file_put_contents($this->dir . '/cur.ttl', "@prefix cur: <https://example.org/def/cur#> .\n");

        $this->expectReleaseError(
            'cur.ttl does not declare the namespace https://ateeducacion.github.io/curriculo_lomloe_canarias/def/cur#.'
        );
        $this->read();
    }

    public function testRejectsAVersionOlderThanTheLoadedOne(): void
    {
        $this->expectReleaseError('Version 1.1.2 is older than the loaded version 1.2.0.');
        $this->read('1.2.0');
    }

    /**
     * @dataProvider notOlderVersions
     */
    public function testAcceptsTheSameOrANewerVersion(string $loadedVersion): void
    {
        $this->assertSame('1.1.2', $this->read($loadedVersion)->getVersion());
    }

    public function notOlderVersions(): array
    {
        return ['same' => ['1.1.2'], 'older patch' => ['1.1.1'], 'older minor' => ['1.0.10']];
    }

    private function read(?string $loadedVersion = null): Release
    {
        return $this->reader->read(
            $this->dir . '/curriculo.json',
            $this->dir . '/curriculo.json.sha256',
            $this->dir . '/cur.ttl',
            $loadedVersion
        );
    }

    private function fixtureData(): array
    {
        return json_decode(file_get_contents(self::FIXTURE . '/curriculo.json'), true);
    }

    private function writeJson(string $json): void
    {
        file_put_contents($this->dir . '/curriculo.json', $json);
        $this->writeChecksum(hash('sha256', $json) . "  curriculo.json\n");
    }

    private function writeChecksum(string $content): void
    {
        file_put_contents($this->dir . '/curriculo.json.sha256', $content);
    }

    private function expectReleaseError(string $message): void
    {
        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage($message);
    }
}
