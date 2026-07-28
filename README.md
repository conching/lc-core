# LC Core

A site-agnostic WordPress starter framework for Library Creative sites, deployed alongside `lc-bricks-mcp`. It ships the reusable machinery every LC site needs and **nothing site-specific**: the custom post types, taxonomies, ACF field groups, and import mappings for a given project live in that project's own **config layer**.

- **Version:** 0.3.0
- **License:** GPL-2.0-or-later
- **Requires PHP:** 7.4
- **Text domain:** `lc-core`
- **Provenance:** extracted from a private predecessor site plugin in July 2026. The extraction report is kept internally.

## What's in the box

| Module | File | Purpose |
|--------|------|---------|
| **Importer** | `inc/importer.php` + `inc/import-lib.php` | Config-driven CSV → CPT upsert. The crown jewel: title-only identity, exact-title upsert fallback, orphaned-key adoption, header-title assertion, URL post-checks, dry-run, and an idempotency self-check. |
| **KSES allowlist** | `inc/kses.php` | Re-allows inert form controls (`input`, `label[for]`) in saved content so CSS-only tab/accordion patterns survive the REST save path. Fully filterable. |
| **Query helpers** | `inc/query-filters.php` | Generic `bricks/posts/query_vars` augmenter that maps request GET params (search / taxonomy / sort) onto a loop. Driven by a per-site param map. |
| **Config accessors** | `inc/config.php` | The seam between core and a site: filter-driven import config, meta-key names, alias meta, bundled-data dir. |
| **Normalizer** | `tools/normalize_workbook.py` + `tools/normalize_lib.py` | Turns a messy client workbook (.xlsx) into importer-ready CSVs, driven by a per-site YAML/JSON config with mandatory header-title assertion and URL sanity checks. |

## The config layer (how a site uses lc-core)

`lc-core` registers **no** CPTs, taxonomies, or fields. A site provides those and tells the importer how a CSV maps onto them by hooking a filter:

```php
add_filter( 'lc_core_import_config', function ( $config ) {
    $config['mrfm_vendor'] = array(
        'signature'        => array( 'vendor_category' ),        // detect this type by header
        'required_columns' => array( 'post_title', 'url' ),      // header-title assertion
        'field_map'        => array(
            'url'             => 'mrfm_url',
            'summary'         => 'mrfm_summary',
            'featured'        => 'mrfm_featured',
            'vendor_category' => '_tax:mrfm_category',            // free-text -> taxonomy term
        ),
        'bool_fields'      => array( 'featured' ),
        'url_fields'       => array( 'url' ),                     // checked ^https?:// after write
    );
    return $config;
} );
```

A **complete worked example** for a fictional "Maple Ridge Farmers Market" site — CPT registration, taxonomies, ACF field groups, import config, and the matching query param map — is in **`config/example-config.php`**. Copy it into your project's own mu-plugin and edit it. See `config/README.md` for the full config schema.

For local demos you can load the example inside lc-core itself by defining `LC_CORE_LOAD_EXAMPLE_CONFIG` before the plugin loads (it is **off** by default so core stays site-agnostic).

## Importer semantics (do not "clean these up")

Every one of these is a fixed production incident carried over from the predecessor plugin. See `CHANGELOG.md` for the incident history.

1. **Identity is the TITLE ONLY.** `import_key` comes from the CSV, or is derived as `md5(lowercased, whitespace-collapsed title)[:12]`. Never fold a correctable field (URL/phone) into identity.
2. **Upsert fallback is exact-title `get_posts` (`post_status => any`)**, skipping trashed posts and posts already claimed this run. **Never `get_page_by_path`** — slugs miss drafts and `-2` duplicates.
3. **Orphaned-key adoption:** an exact-title match is adopted even if it holds a stale key, but never a post another row already claimed this run.
4. **Header-title assertion:** a file is rejected if a `required_columns` title is missing. Positions are never trusted.
5. **URL post-checks:** `url_fields` values are validated against `^https?://` after write; mismatches become report warnings.
6. **Idempotency:** `--dry-run` reports without writing; `--assert-idempotent` (run as a second pass) fails loudly if `created > 0`.

## WP-CLI

```bash
wp lc import path/to/file.csv
wp lc import path/to/file.csv --dry-run
wp lc import path/to/file.csv --assert-idempotent   # run after a real import; exits non-zero if it created anything
wp lc import-dir path/to/data/
```

## Normalizer

```bash
python3 tools/normalize_workbook.py workbook.xlsx tools/example-normalize-config.yml --out ./out
```

The config declares, per output CSV: the source sheet, the column order (with a **mandatory header assertion** so an inserted client column fails loudly instead of silently corrupting a positional map), the taxonomy alias tables, and which columns are URLs. See `tools/example-normalize-config.yml`.

## Development

```bash
tests/run.sh          # php -l + python syntax check + standalone unit tests (no WordPress, no openpyxl)
bin/build-zip.sh      # -> dist/lc-core-0.3.0.zip, honoring .distignore
```

## What is NOT here (left in the predecessor plugin)

The predecessor project's product layer — conditional site-state modes, brand tokens, dynamic tags, admin-bar recolor, mode-specific homepage pickers, event phases — stays in the predecessor plugin. Two GENERALIZABLE modules (`inc/preview.php` client-review links and `inc/redirects.php` page old-slug 301s) are strong candidates for a future lc-core release.
