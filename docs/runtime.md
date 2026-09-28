# Runtime

Each case run prepares a disposable copy of the SUT. It is the isolation boundary between the production checkout and test-only config and data.

## Layout

- `.runtime/cases/<case-id>/sut`: runtime SUT copy with generated `conf/config.inc.php`
- `.runtime/cases/<case-id>/maintenance-runtime`: writable maintenance directory
- `.runtime/cases/<case-id>/persistent-cache`: the SUT's `PERSISTENT_CACHE_DIR`, emptied every run and world-writable so host-uid HTTP tests can flush entries Apache (`www-data`) wrote
- `.runtime/webroot`: symlink to the active case's runtime SUT; Apache serves `/workspace/.runtime/webroot`
- `.runtime/phpunit-cache`: PHPUnit cache

The generated config points at the Compose MariaDB (plain TCP, SSL off) and the case's database, and sets `ALLOW_INSTALL=true` so `index.php` boots while `install.php` exists.

HTTP suites (`export`, `api`, `smoke`, `crawl`) hit the served runtime copy, not the SUT mount.

## Rules

- Never hand-edit `.runtime/`; recreate it from harness code and config.
- Never commit runtime outputs.
