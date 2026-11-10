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
     * Create the table of curriculum imports.
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
        $this->removeDirectory($this->getFilesPath($services));
    }

    /**
     * Directory where the files of each import are stored, one subdirectory per import id.
     *
     * @param ServiceLocatorInterface $services
     * @return string
     */
    public function getFilesPath(ServiceLocatorInterface $services): string
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?? null;
        if ($basePath === null) {
            $basePath = OMEKA_PATH . '/files';
        }
        return $basePath . '/educationalcurriculum';
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
