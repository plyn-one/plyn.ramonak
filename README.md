# Ramonak

A web engine made first for AI agents: a small PHP kernel and modules under explicit contracts, on which a site grows from
one page to a large application without a rewrite. Content lives in a database, with every version kept, publication and
rollback as operations, and as many languages as a site needs, a missing translation served as a machine translation until
someone edits it. Pages are laid out with [Prastora](https://github.com/plyn-one/plyn.prastora).

Status: being designed; the first site it will serve is michalevic.org. The design: [docs/architecture.md](docs/architecture.md).

The engine is under the MIT License ([LICENSE](LICENSE)).

`AGENTS.md`, `miesca.toml`, `śled.toml` and `pračytańnie/` belong to Plyń, the system this repository is a place of; they
are not needed to use the engine.
