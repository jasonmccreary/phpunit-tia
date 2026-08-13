# v2 candidates (explicitly deferred, don't build yet)

All of v1's milestones are complete, and everything below was scoped as
*out of* v1 from the start. Nothing here is scheduled; this is a backlog to
revisit, not a plan in progress.

- **Parallel runner (ParaTest) support** — needs worker/partial-merge machinery comparable to Pest's `Tia.php` (`handleWorker`, `flushWorkerPartial`, `mergeWorkerReplayPartials`); real complexity, don't take on until single-process v1 is solid (it is, as of milestone 4).
- **Remote baseline sync** — fetching a pre-warmed graph from CI cache/artifact storage so a fresh clone doesn't start cold. Useful, not blocking; v1 assumes the graph lives in a local persistent cache or is warmed once in CI and cached by the CI provider's own cache action.
- **Framework `Resolver` packages** (Laravel migrations→tables, Blade static-include walking, Inertia page→component resolution, Symfony equivalents, etc.) — the extension point shipped in milestone 5 (`Contracts\Resolver`, `Config::loadResolvers()`, a throwaway example proven end-to-end in `fixture-app/`). The packages themselves (e.g. a `laravel/phpunit-tia-resolver`) are separate deliverables, not started.
