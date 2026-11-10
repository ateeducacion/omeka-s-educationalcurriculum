# Omeka S Educational Curriculum

[![codecov](https://codecov.io/gh/ateeducacion/omeka-s-educationalcurriculum/branch/main/graph/badge.svg)](https://codecov.io/gh/ateeducacion/omeka-s-educationalcurriculum)

<a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-educationalcurriculum%2Frefs%2Fheads%2Fmain%2Fblueprint.json">
  <img src="https://raw.githubusercontent.com/ateeducacion/omeka-s-educationalcurriculum/refs/heads/main/.github/assets/playground-preview-button.svg" alt="Try EducationalCurriculum in your browser" width="224">
</a><br>
<small><a href="https://ateeducacion.github.io/omeka-s-playground/?blueprint=https%3A%2F%2Fraw.githubusercontent.com%2Fateeducacion%2Fomeka-s-educationalcurriculum%2Frefs%2Fheads%2Fmain%2Fblueprint.json">Try in your browser</a></small>

Omeka S module that loads a published release of the LOMLOE Canary Islands curriculum into an
Omeka S instance. An administrator uploads the release files (`curriculo.json`,
`curriculo.json.sha256` and `cur.ttl`), reviews the analysis report and applies it. Applying the
same release twice changes nothing.

The module is under development; the design lives in the
[curriculum repository](https://github.com/ateeducacion/curriculo_lomloe_canarias) next to the
Omeka contract (`docs/omeka.md`).

## Requirements

- Omeka S 4.2 or later
- PHP 7.4+

## Quick Start (Docker)

- Requirements: Docker Desktop 4+, Make
- Start stack: `make up` then open `http://localhost:8080`
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
├── src/                         # Module classes (EducationalCurriculum\ namespace)
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
