# Crawl Testing

The `crawl` suite is the broadest runtime check: route discovery, authenticated page coverage, direct-file fetches, and anonymous security probes, with saved artifacts for diagnosing PHP errors.

## Configuration

Each case declares `crawl_plans` in `config/matrix.json`. Every plan has an `id`, a `type`, and type-specific settings.

| Type | Behavior |
|---|---|
| `follow_links` | Optional login, then recursive in-scope link following from a start path |
| `php_files` | Direct fetch of every `.php` endpoint under one HTTP-addressable directory |
| `path_probes` | Fixed-path requests asserting expected statuses and no forbidden body content |
| `simulate_event` | Logs in, generates an event with `plugins/simulate_event.php`, then plays its pools with `plugins/simulate_games.php` |

`baseline-default` plans:

- `public-follow-links`: anonymous crawl from the frontpage
- `public-ext-php`: `ext/*.php`
- `superadmin-follow-links`: authenticated crawl from `admin/serverconf`, excluding destructive DB routes
- `anonymous-sensitive-paths`: config, lib, and traversal paths must stay blocked

Customization cases run only `public-follow-links`.

`generated-event` crawls an event built at run time. `generate-and-play-event` (`simulate_event`) takes the simulator form fields in `event` (a `seed` makes it repeatable) and `play` (`winner_mode`, `spirit`). The first generated event is always `SIM001`, which the next plan starts from. Pools are played in rounds, because a playoff pool is only offered once the pools feeding it are played; the plan fails if no pool is played. `generated-event-follow-links` then crawls as the superadmin. It stops at `max_pages`, and `max_pages_per_view` keeps the many per-game and per-team admin pages from using the whole budget. The simulator needs a pool template, which the production schema lacks, so the case loads `fixtures/pool-template.sql`.

Any PHP issue logged during the run fails the case, whichever plan caused it (`docs/reporting.md`). For attribution, read `logs/apache-error.log` in the run's report directory.

## Artifacts

Each plan writes to `crawl/<plan-id>/`: the crawler log, a manifest, downloaded pages, and the probe log. The summary has one entry per plan with status, duration, log path, artifact root, and details such as page count or failed probes. If a helper script fails before logging, the harness writes a startup-failure log (command, exit code, output) at the plan's log path.

Keep small deterministic regression checks in `smoke`, not here.
