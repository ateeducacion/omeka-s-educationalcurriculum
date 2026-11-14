<?php
declare(strict_types=1);

namespace EducationalCurriculum;

use FilesystemIterator;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Loads published releases of the LOMLOE Canary Islands curriculum into Omeka S.
 */
class Module extends AbstractModule
{
    const SKOS_NAMESPACE = 'http://www.w3.org/2004/02/skos/core#';

    /**
     * Retrieve the configuration array.
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Create the table of curriculum imports and install the SKOS vocabulary if it is missing.
     *
     * @param ServiceLocatorInterface $services
     */
    public function install(ServiceLocatorInterface $services)
    {
        $connection = $services->get('Omeka\Connection');
        $schema = file_get_contents(__DIR__ . '/data/install/schema.sql');
        foreach (array_filter(array_map('trim', explode(";\n", $schema))) as $sql) {
            $connection->executeStatement($sql);
        }
        $this->installSkos($services);
    }

    /**
     * Drop the table of imports and their uploaded files.
     *
     * Items, item sets, resource templates and vocabularies are kept: other modules use them.
     *
     * @param ServiceLocatorInterface $services
     */
    public function uninstall(ServiceLocatorInterface $services)
    {
        $services->get('Omeka\Connection')->executeStatement('DROP TABLE IF EXISTS curriculum_import');
        $this->removeDirectory(self::filesPath($services->get('Config')));
    }

    /**
     * Directory where the files of each import are stored, one subdirectory per import id.
     *
     * @param array $config The application configuration
     */
    public static function filesPath(array $config): string
    {
        $basePath = $config['file_store']['local']['base_path'] ?? null;
        if ($basePath === null) {
            $basePath = OMEKA_PATH . '/files';
        }
        return $basePath . '/educationalcurriculum';
    }

    /**
     * Import the bundled SKOS vocabulary unless one with its namespace is already installed.
     */
    private function installSkos(ServiceLocatorInterface $services): void
    {
        $api = $services->get('Omeka\ApiManager');
        if ($api->search('vocabularies', ['namespace_uri' => self::SKOS_NAMESPACE])->getTotalResults() > 0) {
            return;
        }
        $services->get('Omeka\RdfImporter')->import('file', [
            'o:namespace_uri' => self::SKOS_NAMESPACE,
            'o:prefix' => 'skos',
            'o:label' => 'SKOS',
            'o:comment' => 'Simple Knowledge Organization System',
        ], [
            'file' => __DIR__ . '/data/vocabularies/skos.rdf',
            'format' => 'rdfxml',
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
