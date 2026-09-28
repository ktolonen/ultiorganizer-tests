# Crawl Testing

The `crawl` suite is the broadest runtime check: route discovery, authenticated page coverage, direct-file fetches, and anonymous security probes, with saved artifacts for diagnosing PHP errors.

## Configuration

Each case declares `crawl_plans` in `config/matrix.json`. Every plan has an `id`, a `type`, and type-specific settings.

| Type | Behavior |
|---|---|
| `follow_links` | Optional login, then recursive in-scope link following from a start path |
| `php_files` | Direct fetch of every `.php` endpoint under one HTTP-addressable directory |
| `path_probes` | Fixed-path requests asserting expected statuses and no forbidden body content |

`baseline-default` plans:

- `public-follow-links`: anonymous crawl from the frontpage
- `public-ext-php`: `ext/*.php`
- `superadmin-follow-links`: authenticated crawl from `admin/serverconf`, excluding destructive DB routes
- `anonymous-sensitive-paths`: config, lib, and traversal paths must stay blocked

Customization cases run only `public-follow-links`.

## Artifacts

Each plan writes to `crawl/<plan-id>/`: the crawler log, a manifest, downloaded pages, and the probe log. The summary has one entry per plan with status, duration, log path, artifact root, and details such as page count or failed probes. If a helper script fails before logging, the harness writes a startup-failure log (command, exit code, output) at the plan's log path.

Keep small deterministic regression checks in `smoke`, not here.
