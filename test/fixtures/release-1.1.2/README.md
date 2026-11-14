# Release 1.1.2 fixture

Reduced copy of the assets of release
[`v1.1.2`](https://github.com/ateeducacion/curriculo_lomloe_canarias/releases/tag/v1.1.2)
of the curriculum: Mathematics in 1.º ESO (`asig:SMAT01`) with its chain `etapa:ESO` →
`curso:S01` → `materia:SMAT`, its specific competences, competence blocks, criteria, knowledge
blocks and basic knowledge (125 nodes, in corpus order).

- `curriculo.json`: `corpus` copied unchanged; `nodos` filtered to the nodes above. The only
  edit is `materia:SMAT`, whose `cursos` keeps only `curso:S01`, so every link points inside
  the fixture.
- `curriculo.json.sha256`: `sha256sum` of the reduced `curriculo.json`.
- `cur.ttl`: copied unchanged from the release.
