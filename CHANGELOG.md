# Changelog

All notable changes to LC Core. Format loosely follows Keep a Changelog; this plugin uses semantic-ish versioning.

## [0.2.2] — 2026-07-16

### Added (example per-site config)
- an element-id-keyed `bricks/posts/query_vars` taxonomy filter for the four
  product-index loops — Bricks silently drops raw
  `tax_query`/`taxQuery`/`taxonomyQuery` keys from stored loop settings
  (meta_query passes; tested 2026-07-16).

### Changed (example per-site config)
- `product_availability` select `return_format` → `label` so Bricks renders
  "Year-Round"/"Seasonal" on cards; writes still accept raw values.

## [0.2.1] — 2026-07-16

### Added (example per-site config)
- a server-rendered category-pill shortcode whose active state reflects
  `?category=` with zero JS; pairs with the query param map.

## [0.2.0] — 2026-07-16

### Added
- **Site-config auto-loader:** `config/site-config.php` is loaded automatically when
  present, so a deployment ships one zip with its per-site layer inside (no separate
  mu-plugin). The file is gitignored — the generic repo stays site-agnostic.
- **example per-site config** (ships in a per-site deploy only): content + product CPTs
  (`publicly_queryable => false`, `show_in_rest => true`), `category` /
  `product_category` taxonomies with idempotent term seeding on
  activate/upgrade, ACF field groups (content external-URL + featured flag; product
  English name / availability / description / 12 month-state selects,
  `show_in_rest` for REST content entry), importer contract for both types,
  content `?category=` query param map, and a bespoke availability-matrix
  server-rendered shortcode module.

## [0.1.0] — 2026-07-14

Initial release. Extracted from a private predecessor site plugin in July 2026 as a
site-agnostic starter framework. This is an **extraction, not a rewrite**: proven
code was moved, renamed to the `lc_core_*` function prefix, text domain `lc-core`, and
import-key meta `_lc_import_key`, and site-specific data lifted into a
per-site config layer. The procedural, prefix-based style of the predecessor plugin is preserved.

### Added — Importer framework (`inc/importer.php`, `inc/import-lib.php`)
The crown jewel, carried over with its production semantics intact:
- **Title-only identity.** `import_key` comes from the CSV or is derived from the
  title alone (`md5(lowercased, whitespace-collapsed title)[:12]`). Carries
  a hard-won lesson from the predecessor plugin: keys derived from `title|url` spawned **26
  duplicates** on 2026-07-09 the moment an import legitimately corrected a URL.
  *New in lc-core:* a pure-PHP `lc_core_derive_import_key()` twin of the Python
  normalizer's `hkey()`, so the importer is self-sufficient and the identity rule
  is unit-testable without WordPress.
- **Exact-title upsert fallback.** Matches by import-key meta, then by exact
  `post_title` via `get_posts` (`post_status => any`), skipping trashed posts and
  posts already claimed by an earlier row of the same run. **Never
  `get_page_by_path`** — a fix from the predecessor plugin, because slug lookups miss drafts
  (empty `post_name`) and `-2`-suffixed historical duplicates. An earlier predecessor version used
  the buggy `get_page_by_path` path; that version is deliberately NOT carried over.
- **Orphaned-key adoption guard** (as implemented in the predecessor plugin): adopt an
  exact-title post even if it holds a stale key, but never one another row claimed
  this run.
- **Header-title assertion** (`required_columns`): a file is rejected loudly if a
  required column title is missing. Generalizes the normalizer's positional header
  guard, added after an inserted client column silently corrupted two imports.
- **URL post-checks** (`url_fields`): written values are validated against
  `^https?://`; mismatches become report warnings. Generalizes the normalizer's
  malformed-URL sweep.
- **Dry-run mode** (`--dry-run`): detect + match, write nothing, report what would
  happen.
- **Idempotency self-check** (`--assert-idempotent`): a second identical pass fails
  loudly (WP-CLI exit non-zero; `idempotency_ok => false`) if it reports
  `created > 0`. New in lc-core — operationalizes the "safe to re-run" promise.
- Generic term resolution with a filterable alias-meta key, attachment-by-filename
  lookup, a Tools ▸ LC Import admin screen, and `wp lc import` / `wp lc import-dir`.

### Added — KSES allowlist (`inc/kses.php`)
Extracted from the predecessor plugin. Re-allows inert form controls (`input`, `label[for]`)
so CSS-only tab/accordion patterns survive the Bricks REST save path. Made fully
filterable (`lc_core_kses_enable`, `_contexts`, `_input_atts`, `_label_atts`,
`_allowed_html`).

### Added — Query helpers (`inc/query-filters.php`)
Extracted the generic `bricks/posts/query_vars` augmenter (from the predecessor plugin). Maps
request GET params (search / taxonomy / sort) onto a loop, driven by a per-site
param map. Encodes the doctrine: **per-loop constraints belong natively in the
element's own query settings; this hook only augments from request params.**
The predecessor plugin's urgent-pinning / conditional-state logic was intentionally left behind as per-site
product code.

### Added — Config layer (`inc/config.php`, `config/example-config.php`, `config/README.md`)
Filter-driven per-site config seam (`lc_core_import_config`,
`lc_core_query_param_map`, meta-key + alias-meta filters, bundled-data dir). Ships a
complete worked example for a fictional "Maple Ridge Farmers Market" site (CPTs,
taxonomies, ACF field groups, import config, query param map). **No predecessor-project
strings exist in lc-core core code.**

### Added — Normalizer (`tools/normalize_workbook.py`, `tools/normalize_lib.py`)
Generalized from the predecessor plugin's companion `normalize_workbook.py`. Column maps, sheet
selection, taxonomy alias tables, and URL columns are driven by a per-site YAML/JSON
config (`tools/example-normalize-config.yml`). Retains the **mandatory header-title
assertion** (fail loudly on column drift) and the **post-run URL sanity sweep**.
Pure logic (normalization, key derivation, alias resolution, header assertion) is
split into `normalize_lib.py` for dependency-free unit testing.

### Added — Tooling
- `tests/` — standalone unit tests (no WordPress, no openpyxl): import-key
  derivation (PHP ⇄ Python parity spec), title normalization, header assertion,
  type detection, URL validation, and the normalizer's alias resolution + mapping.
  `tests/run.sh` also runs `php -l` and `python3 -m py_compile` across the tree.
- `bin/build-zip.sh` — produces `dist/lc-core-0.1.0.zip` honoring `.distignore`.

### Not included (left in the predecessor plugin; see extraction report §5)
Conditional site-state system, brand tokens, dynamic tags, admin-bar recolor, latest-
updates / immediate-support / event-phase pickers, gtranslate toggle. Two
GENERALIZABLE modules deferred to a future release: `inc/preview.php` (logged-out
client review links) and `inc/redirects.php` (page old-slug 301 shim).
