# v2 candidates (explicitly deferred, don't build yet)

Extracted from `TIA.md` §8, the original v1 design doc — all of v1's
milestones are complete (see `TIA.md` §10), and everything below was scoped
as *out of* v1 from the start. Nothing here is scheduled; this is a backlog
to revisit, not a plan in progress.

- **`--filter`-based selection wrapper**: a `vendor/bin/phpunit-tia` binary that computes the affected set the same way, then re-execs real `phpunit --filter '<computed regex>'`. This is a legitimate complement to the trait (skips the process-level cost of even instantiating unaffected `TestCase`s, not just their bodies) but is mechanically a wrapper trick, not an extension capability — `PHPUnit\Runner\Extension\Facade` has no test-selection hook (confirmed by reading its full public surface). Keep it a separate bin/package so the core extension+trait stays usable standalone.
- **Parallel runner (ParaTest) support** — needs worker/partial-merge machinery comparable to Pest's `Tia.php` (`handleWorker`, `flushWorkerPartial`, `mergeWorkerReplayPartials`); real complexity, don't take on until single-process v1 is solid (it is, as of milestone 4).
- **Remote baseline sync** — fetching a pre-warmed graph from CI cache/artifact storage so a fresh clone doesn't start cold. Useful, not blocking; v1 assumes the graph lives in a local persistent cache or is warmed once in CI and cached by the CI provider's own cache action.
- **Framework `Resolver` packages** (Laravel migrations→tables, Blade static-include walking, Inertia page→component resolution, Symfony equivalents, etc.) — the extension point shipped in milestone 5 (`Contracts\Resolver`, `Config::loadResolvers()`, a throwaway example proven end-to-end in `fixture-app/`). The packages themselves (e.g. a `laravel/phpunit-tia-resolver`) are separate deliverables, not started.
- **Replaying cached failures** — considered and rejected for v1 (see `docs/decisions.md`); revisit only with a concrete case for it. A fresh failure needs a fresh stack trace/diff, not a stale cached message, so this isn't a "TODO" so much as a closed decision pending new evidence.
