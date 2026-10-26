# Hooks

## Filters

### `r404s_should_log`
Return `false` to skip logging a 404 (for example to ignore bot paths).

```php
add_filter( 'r404s_should_log', function ( $should_log, $path ) {
	return 0 === strpos( $path, '/wp-content/' ) ? false : $should_log;
}, 10, 2 );
```

| Param | Type | Description |
|---|---|---|
| `$should_log` | bool | Default `true`. |
| `$path` | string | Normalised request path (lowercase, no query string). |

### `r404s_max_rows`
Maximum distinct 404 paths kept (default `10000`). Past it, only hits on already-logged paths are counted. The row count is cached for 60 seconds, so the cap is approximate.

### `r404s_min_similarity`
Minimum similarity percentage (0-100) for a closest-match suggestion. Default `60`.

```php
add_filter( 'r404s_min_similarity', fn() => 75 );
```
