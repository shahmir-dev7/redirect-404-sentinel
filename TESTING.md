# Testing: Redirect & 404 Sentinel 1.0.0

All runs 2026-10-26, Windows host, Docker Desktop, wp-env, WordPress 7.1.3.

| Check | Command | Result |
|---|---|---|
| Syntax | `php -l` on every file | clean |
| PHPCS | `phpcs --standard=phpcs.xml.dist` (WordPress-Extra, WordPress-Docs, PHPCompatibilityWP `7.4-`) | 0 errors, 0 warnings (`tests/` excluded) |
| PHPStan | `phpstan analyse -c phpstan.neon.dist` level 6 | 0 errors (level 8 not attempted) |
| Unit (no WordPress) | `phpunit -c phpunit.unit.xml` | 6 tests, 19 assertions OK |
| WordPress suite, PHP 8.3 | `wp-env run tests-cli ... phpunit -c phpunit.xml.dist --coverage-text` | 27 tests, 75 assertions OK; **85.66% lines** on `includes/` (239/279) |
| WordPress suite, PHP 7.4.33 | same, wp-env `phpVersion` 7.4 | 27 tests, 75 assertions OK |
| Plugin Check | `wp plugin check redirect-404-sentinel` on the **release zip** | 0 errors, 6 warnings (below) |
| Browser (Playwright, Chromium) | `npx playwright test` against a fresh site with the zip installed | 1 passed |
| Package | `.distignore` zip, installed on a fresh site via WP-CLI, activated, smoke-tested | OK: tables created, 404 logged over HTTP, CLI redirect served a 301 |
| Uninstall | UI deactivate + delete, then `SHOW TABLES`, option list, cron list | 0 tables, 0 options, no cron event |
| Performance | queries per request via `get_num_queries()`, plugin off vs on | normal page 22 vs 22 (+0); 404 page +3 when caches are warm (first cold hit +11) |

Release zip SHA-256: `F6CDBDAE45C6AF96474B8EE1299A7309C981D22DF187CBA1F9938E65AB628F2D` (rebuilt for each release; this one is the tested build).

## Plugin Check warnings (6), explained
All are `WordPress.DB.DirectDatabaseQuery` (DirectQuery + NoCaching) on three statements that touch the plugin's own custom tables: `Logger::delete()` (`Logger.php:66`), `Redirects::delete()` (`Redirects.php:92`) and the uninstall `DROP TABLE` (`uninstall.php:16`). The other direct queries carry an explicit `phpcs:ignore` and are not reported. Writes cannot be cached, and there is no core API for custom tables. Reads that matter are cached where it counts (row-count transient, candidate-slug transient).

## What the tests do not cover (honest limits)
- **Multisite:** network activation, `wp_initialize_site` and multisite uninstall branches are not exercised (~12 lines). Not tested on a multisite install.
- **Coverage scope:** `Plugin::init()` and the main file run before coverage starts, so they are not counted.
- **PHP × WordPress matrix:** only PHP 8.3 and 7.4.33 on WordPress 7.1.3 were run locally. WordPress "latest-1" and PHP 8.4/8.5 are defined in `.github/workflows/ci.yml` as **CI-only, not locally verified**; the CI workflow itself has not been run.
- **PHPStan level 8** was not attempted.
- **Browser coverage:** Chromium only, one scenario.
- **Security review** is by the author, not independent (see SECURITY_REVIEW.md).

## Notes on running the WordPress suite
The `tests-cli` container needed PHPUnit 9.6 and `yoast/phpunit-polyfills` installed globally (`composer global require -W phpunit/phpunit:^9.6 yoast/phpunit-polyfills:^2.0`) and `WP_TESTS_PHPUNIT_POLYFILLS_PATH` set. Coverage needed `pcov` (`pecl install pcov` in the container).

## Browser test re-verified (2026-10-26)
The browser test's delete step originally had a race (it could click Delete before WordPress's JavaScript loaded and follow a confirmation link that deletes nothing) and a check that could pass falsely. Both were fixed: the test now waits, confirms from the plugin list that the plugin is gone, and uses a positive control for the clean-database check. It passes on the final zip. The release zip changed only in the author header since the numbers above were taken; tests were not otherwise re-run on it beyond Plugin Check (0 errors) and this browser test.
