# phlix-plugin-omdb

[![tests](https://github.com/detain/phlix-plugin-omdb/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-omdb/actions/workflows/test.yml)

> OMDb metadata provider for [Phlix](https://github.com/detain/phlix) — IMDb/RT/Metascore ratings aggregation for movies and TV.

Queries the [OMDb API](http://www.omdbapi.com/) to enrich Phlix media items with
IMDb ID, Rotten Tomatoes critic rating, and Metascore ratings. Ratings are
stored in the `metadata_ratings` table and feed into Phlix's rating aggregation
pipeline.

## Features

- **IMDb ID resolution** — search by title + year to resolve the canonical IMDb ID
- **Multi-source ratings** — captures IMDb, Rotten Tomatoes, and Metascore scores
- **Non-blocking HTTP** — async HTTP client compatible with Workerman's event loop
- **Rate limiting** — configurable request throttling to respect OMDb API limits
- **Response caching** — configurable in-memory cache TTL to reduce API calls
- **TLS verification toggle** — option to disable SSL verification for self-hosted proxies
- **Ratings ingestion** — writes ratings directly to `metadata_ratings` for aggregation

## Install

The plugin is unsigned by design — install via the Phlix admin UI:

1. Log in to your Phlix server as an admin user (`users.is_admin = 1`).
2. Browse to `/admin/plugins`.
3. Paste this URL into the **Install from URL** form and submit:

   ```
   https://raw.githubusercontent.com/detain/phlix-plugin-omdb/main/plugin.json
   ```

4. The server downloads the manifest, validates it, runs `composer install --no-dev`,
   and stores a row in the `plugins` table. The plugin lands **disabled** by default.
5. Flip the toggle in the table to enable it.
6. Enter your OMDb API key in the plugin settings (get one at http://www.omdbapi.com/apikey.aspx).

## Settings

| Setting | Type | Required | Secret | Default | Description |
|---------|------|----------|--------|---------|-------------|
| `enabled` | boolean | No | No | `false` | Master on/off for OMDb rating enrichment. Optional; default off. |
| `api_key` | string | **Yes** | Yes | `null` | Your OMDb API key. The free tier allows 1,000 requests/day. |
| `use_ssl_verification` | boolean | No | No | `true` | Verify OMDb's TLS certificate. Optional; default on. Leave on unless debugging. |
| `cache_ttl_seconds` | integer | No | No | `86400` | How long to cache OMDb responses. Optional; default 86400 (24 hours). |

### Where to get your OMDb API key

The `api_key` setting is required. Request a free key at
<https://www.omdbapi.com/apikey.aspx> — the free tier allows 1,000 requests/day.

## What it does

When enabled, the plugin registers as a `MetadataSourceInterface` provider. When
the Phlix metadata manager requests details for a media item:

1. The plugin receives the search query (title + optional year)
2. It queries OMDb's search endpoint to find matching entries
3. It fetches full details including ratings for the selected IMDb ID
4. Ratings are written to `metadata_ratings` and feed into the rating aggregation

## Supported media types

- `movie`
- `series`

## Running tests locally

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse src --level=9
vendor/bin/phpcs src --standard=PSR12
```

## License

MIT — see [`LICENSE`](LICENSE).
