# The per-site config layer

`lc-core` ships machinery, not product. Every site-specific decision — post types, taxonomies, fields, import mappings, finder params — lives in a config layer **owned by the site**, not by lc-core.

## Rules

1. **Never edit lc-core files on a site.** lc-core must stay zip-replaceable.
2. Copy `example-config.php` into your site's plugin or mu-plugin and rename the `mrfm_` prefix to your site's prefix.
3. Slugs (CPT names, taxonomy names, field names) are **the contract** — the importer, the Bricks templates, and the workbook normalizer all reference them. Never change them after content exists.

## Seams lc-core exposes

| Filter / hook | Purpose |
|---------------|---------|
| `lc_core_import_config` | Per-type import config: `signature`, `required_columns`, `field_map`, `bool_fields`, `url_fields`. |
| `lc_core_query_param_map` | Per-type finder GET-param rules: `search_params`, `tax_params`, `sort_key`, `sort_map`. |
| `lc_core_import_key_meta` | Meta key storing a post's import key (default `_lc_import_key`). |
| `lc_core_import_notes_meta` | Meta key storing per-row import notes (default `_lc_import_notes`). |
| `lc_core_term_alias_meta` | Term-meta key holding the JSON alias array (default `lc_aliases`). |
| `lc_core_import_data_dir` | Absolute path of bundled CSVs for one-click / CLI directory import. |
| `lc_core_kses_*` | Widen/narrow the KSES form-control allowlist (see `inc/kses.php`). |
| `lc_core_activate` (action) | Fires on plugin activation — seed terms here. |
| `lc_core_upgrade` (action) | Fires once per version bump (zip-replace deploys) — re-seed / migrate here. |

## Import config schema

```php
$config['my_cpt'] = array(
    // Header column(s) that uniquely identify this type in a CSV. All must be
    // present for the type to be detected. First config entry that matches wins.
    'signature'        => array( 'my_signature_column' ),

    // Header-title assertion: the file is REJECTED if any of these column titles
    // is missing. 'post_title' is always implied. This is the guard against the
    // client inserting/moving columns (positional drift corrupted two
    // predecessor-project imports before this existed).
    'required_columns' => array( 'post_title', 'url' ),

    // CSV column => target.
    //   'field_name'        writes via ACF update_field() (falls back to post meta)
    //   '_tax:my_taxonomy'  routes a free-text column into a taxonomy term,
    //                       resolved through slug -> name -> alias meta; a blank
    //                       cell CLEARS the term (stale-term cleanup).
    'field_map'        => array(
        'url'     => 'my_url',
        'summary' => 'my_summary',
        'kind'    => '_tax:my_kind',
    ),

    // Columns coerced to strict 1/0 (only the literal "1" is truthy).
    'bool_fields'      => array( 'featured' ),

    // Columns whose written values must match ^https?:// (report warning if not).
    'url_fields'       => array( 'url' ),
);
```

Reserved CSV columns handled by the importer itself: `post_title` (identity — required), `post_status` (`publish`/`draft`, default `publish`), `import_key` (optional; derived from the title when absent), `image_filename` (featured image matched against the Media Library; no-op until the file is uploaded, re-run to attach), `import_notes` (stored in the notes meta). Columns named exactly like a registered taxonomy of the post type are treated as pipe-separated term-slug lists.

## The workbook normalizer's config

The Python tool (`tools/normalize_workbook.py`) has its own per-site config (YAML or JSON) describing the client workbook's sheets and columns — see `tools/example-normalize-config.yml`. Keep the two configs in sync: the normalizer's `output.columns` must produce the headers your `lc_core_import_config` expects.
