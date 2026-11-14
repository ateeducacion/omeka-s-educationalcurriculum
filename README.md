# Omeka S Educational Curriculum

[![codecov](https://codecov.io/gh/ateeducacion/omeka-s-educationalcurriculum/branch/main/graph/badge.svg)](https://codecov.io/gh/ateeducacion/omeka-s-educationalcurriculum)

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-educationalcurriculum%2Frefs%2Fheads%2Fmain%2Fblueprint.json">
  <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-educationalcurriculum/refs/heads/main/.github/assets/playground-preview-button.svg" alt="Try EducationalCurriculum in your browser" width="224">
</a><br>
<small><a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-educationalcurriculum%2Frefs%2Fheads%2Fmain%2Fblueprint.json">Try in your browser</a></small>

Omeka S module that loads a published release of the LOMLOE Canary Islands curriculum into an
Omeka S instance, following the Omeka contract of the
[curriculum repository](https://github.com/ateeducacion/curriculo_lomloe_canarias)
(`docs/omeka.md`): one item per node of `curriculo.json`, with the `cur` classes and the SKOS
properties, in the item set «Currículo LOMLOE de Canarias». Applying the same release twice
changes nothing.

## Usage

Only global administrators see the **Curriculum** page in the module navigation. It shows the
loaded version, the current and retired terms and the history of imports.

1. **Upload release.** Upload the three files of a published release (`v<version>` assets):
   `curriculo.json`, `curriculo.json.sha256` and `cur.ttl`. They are checked before anything is
   saved: the sha256, the structure of the JSON, the URI base, a version not older than the loaded
   one and no other import in progress.
2. **Review the report.** The release is analyzed in a background job. The import page shows the
   new, modified and retired items, the changes of the `cur` vocabulary and any blocking error,
   such as an item of the item set that is not in the release.
3. **Apply.** Another job writes the approved report: the `cur` vocabulary, one resource template
   per class, the item sets, the items with their literals and then their links, and finally the
   loaded version (`dcterms:hasVersion`). If the items changed after the analysis, nothing is
   written and the import must be analyzed again.

An import that was not applied can be discarded. An interrupted apply step is analyzed again; the
new report shows what is left. Properties and item sets added to the items by hand or by other
modules are kept; values of the properties the module owns are replaced. Uninstalling the module
removes its imports and their files, not the items, item sets, templates or vocabularies.

Statuses of an import: `analyzing`, `ready`, `blocked` (the report has errors), `stale` (analyze
again), `applying`, `interrupted` and `applied`.

### Performance

Measured with release 1.1.2 (15,438 nodes) on the Docker stack: the first analysis takes about
2 s and the apply job about 2 minutes; analyzing the loaded release again takes under 1 s. The
analysis of a loaded curriculum peaks at about 400 MB, so jobs need a PHP `memory_limit` of at
least 512M.

## Requirements

- Omeka S 4.2 or later
- PHP 7.4+
- The module installs the SKOS vocabulary unless it is already installed.

## Quick Start (Docker)

- Requirements: Docker Desktop 4+, Make
- Start stack: `make up` then open `http://localhost:8080` (set `OMEKA_PORT` to use another port, e.g. `OMEKA_PORT=8081 make upd`)
- Stop stack: `make down`

The stack uses the image `erseco/alpine-omeka-s:4.2` and applies `blueprint.json` on every start,
the same file as the Omeka S Playground (users, this module, the demo site).

Users created automatically (from `blueprint.json`):
- `admin@example.com` (global_admin) password: `password`
- `editor@example.com` (editor) password: `1234`

### Useful Make Targets

- `make up` / `make upd`: Run in foreground/background
- `make down` / `make clean`: Stop, optionally remove volumes
- `make logs`: Tail container logs
- `make shell`: Shell into the `omekas` container
- `make enable-module`: Enable this module inside Omeka S
- `make lint`: Run PHP_CodeSniffer (PSR2)
- `make test`: Run PHPUnit tests
- `make package VERSION=x.y.z`: Build a distributable ZIP

Run `make help` to see all targets.

## Project Structure

```text
EducationalCurriculum/
├── Module.php                   # Main module class (entry point)
├── config/
│   ├── module.ini               # Module metadata
│   └── module.config.php        # Services, routes, views, translations
├── data/                        # Install SQL and the bundled SKOS vocabulary
├── src/
│   ├── Release/                 # Reads and checks the release files
│   ├── Mapping/                 # Node → item values, as the contract says
│   ├── Sync/                    # Current state, planner, writers and synchronizer
│   ├── Job/                     # Analyze and Apply jobs
│   ├── Admin/, Controller/      # The Curriculum pages
│   └── Entity/                  # One row per import
├── view/                        # Templates
├── language/                    # Translations (.po/.mo)
├── test/                        # Unit tests and bootstrap
├── docker-compose.yml           # Dev stack using alpine-omeka-s:4.2
├── Makefile                     # Dev helpers (docker, i18n, tests, packaging)
└── README.md                    # This file
```

## Translations

Interface strings are in English with a Spanish translation in `language/es.po`. Run
`make i18n` to extract strings, update the catalog, check for untranslated strings and compile it.

## License

Published under the GNU GPLv3 license. See [LICENSE](LICENSE).

## Coverage

Run `make test-coverage` with PCOV or Xdebug enabled. CI measures `Module.php` and all PHP files in `src/`, requires at least 90% line coverage, and uploads the Clover report to Codecov using GitHub OIDC. Codecov requires 90% for both project and patch coverage.
