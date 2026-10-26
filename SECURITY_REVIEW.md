# Security review: Redirect & 404 Sentinel 1.0.0

Manual review of every superglobal read, output, query, file operation and endpoint. Reviewer: the author; not independent.

## Superglobals
| Location | Input | Handling |
|---|---|---|
| `Redirects::handle_404` | `$_SERVER['REQUEST_URI']` | `wp_unslash`, then `Paths::normalize` (strips query, control chars, lowercases, 190-char cap). |
| `Redirects::handle_404` | `$_SERVER['HTTP_REFERER']` | `wp_unslash`, then `esc_url_raw` and truncated to 191 chars in `Logger::log`. Never rendered in the admin screen. |
| `Admin::handle_create` | `$_POST['source']`, `['target']` | After `guard()`: `sanitize_text_field(wp_unslash())`, then `Redirects::add` (`Paths::normalize`, `esc_url_raw`). |
| `Admin::handle_delete_redirect`, `handle_dismiss` | `$_POST['id']` | `absint`, used with `$wpdb->delete` and `%d`. |
| `Admin::handle_import` | `$_FILES['csv']` | `is_uploaded_file`, 1 MB cap, parsed with `str_getcsv`, 5000-row cap, each cell sanitised and routed through `Redirects::add`. |
| `Admin::render` | `$_GET['r404s_msg']` | `sanitize_key`; only matched against a fixed allowlist, or the `imported-<int>` pattern cast to `int`. |

## Endpoints
No REST routes and no `wp_ajax_*`. Five `admin_post_*` actions (create, delete redirect, dismiss, export, import). Each calls `guard()`: `current_user_can( 'manage_options' )` (403 on failure) then `check_admin_referer`. Handlers are not registered for `admin_post_nopriv_*`. The settings screen has its own capability check; its option goes through the Settings API with a sanitize callback (clamped 1-365).

## Output
All echoed dynamic values pass `esc_html`, `esc_attr` or `esc_url`, or are cast to `int`. `$post_url` is escaped once with `esc_url` and reused; the `phpcs:ignore` comments on those lines say so.

## Queries
Every query with a variable uses `$wpdb->prepare` or `$wpdb->delete` with formats. Table names are built from `$wpdb->prefix` and constants, never from input. Queries without variables (`all()`, the `COUNT(*)`, the uninstall `DROP TABLE`) are static strings.

## Files
Only the CSV import reads an uploaded file; nothing is written to disk. CSV export neutralises formula injection (`Paths::csv_safe`) and the import strips that prefix again.

## Findings
1. **Fixed: unbounded log growth.** Random-URL bots could add unlimited rows. Added `r404s_max_rows` (default 10,000) and 60-second row-count caching. The cap is approximate under heavy concurrent load.
2. **Accepted: redirect targets may be external.** Only users with `manage_options` can create them, so open redirect is by design. Targets pass `esc_url_raw` (blocks `javascript:` and other disallowed protocols). `wp_redirect` is used instead of `wp_safe_redirect` for that reason.
3. **Accepted: referrer is stored.** It is truncated and escaped on storage; no IPs or user agents are stored.
4. **Not yet verified:** the unauthorised-request and nonce tests exist in `tests/phpunit/IntegrationTest.php` but had not been run when this was written. See `TESTING.md` for the current result.
