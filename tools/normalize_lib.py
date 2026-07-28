"""
LC Core — pure normalization logic (no openpyxl, no I/O).

Extracted and generalized from the predecessor project's companion script
normalize_workbook.py. Everything here is import-safe and
unit-testable without a workbook, a config file, or WordPress.

Doctrine carried over (do not "simplify"):
  * Identity is the TITLE ONLY (hkey). Keys derived from title|url spawned 26
    duplicates on 2026-07-09 when an import legitimately corrected URLs.
  * Header-title assertion, fail loudly. Positional mapping corrupted two imports
    when the client inserted columns; we match columns by TITLE and refuse to run
    when a declared title is missing.
  * Post-run URL sanity checks (warn on values that aren't ^https?://).
"""

import hashlib
import re
import unicodedata

# Default splitter for multi-value taxonomy cells: ; , / | + and the word "and".
DEFAULT_SPLIT_RE = r"[;,/]| and |\+|\|"


def spreadsheet_safe(value):
    """Escape a CSV cell that spreadsheet apps could evaluate as a formula.

    This is intentionally opt-in at the CLI because the normal output is consumed
    directly by WordPress and a leading apostrophe would become imported data.
    """
    if not isinstance(value, str) or not value:
        return value
    return "'" + value if value[0] in ("=", "+", "-", "@") else value


def norm(s):
    """Normalize a cell: NFC, curly quotes -> ʻokina, collapse whitespace, trim."""
    if s is None:
        return ""
    s = unicodedata.normalize("NFC", str(s)).replace("‘", "ʻ").replace("’", "ʻ")
    return re.sub(r"\s+", " ", s).strip()


def hkey(title, url=None):
    """Stable 12-char import key from the TITLE ONLY.

    The url parameter is deliberately accepted and ignored (call-site
    compatibility with the historical signature; identity must never depend on a
    correctable field). Matches lc_core_derive_import_key() in inc/import-lib.php.
    """
    t = norm(title).lower()
    if not t:
        return ""
    return hashlib.md5(t.encode("utf-8")).hexdigest()[:12]


def build_lookup(aliases):
    """{tax: {slug: [alias, ...]}} -> {tax: {alias_lower: slug}}.

    The slug itself and its hyphen-to-space form are implicitly valid aliases.
    """
    lookup = {}
    for tax, slug_map in (aliases or {}).items():
        table = {}
        for slug, alias_list in (slug_map or {}).items():
            table[slug.lower()] = slug
            table[slug.replace("-", " ").lower()] = slug
            for a in alias_list or []:
                table[norm(a).lower()] = slug
        lookup[tax] = table
    return lookup


def resolve(lookup, tax, raw, split_re=DEFAULT_SPLIT_RE):
    """Map a raw multi-value cell to term slugs via the alias table.

    Returns (slugs, unmatched). Pieces mapped to the empty-string slug are
    deliberate no-ops (noise vocabulary); pieces with no mapping at all are
    reported as unmatched so a human reviews them.
    """
    out, unmatched = [], []
    if not raw:
        return out, unmatched
    table = lookup.get(tax, {})
    for piece in re.split(split_re, norm(raw), flags=re.I):
        p = norm(piece).lower().rstrip(".").strip()
        if not p:
            continue
        slug = table.get(p)
        if slug is None:
            unmatched.append(piece.strip())
        elif slug:
            if slug not in out:
                out.append(slug)
    return out, unmatched


def find_header_row(rows, required_titles, max_scan=20):
    """Locate the header row and build a title -> column-index map.

    rows: iterable of row lists (already norm()'d cells are fine but not required).
    required_titles: every title the config's column specs reference.

    Scans the first max_scan rows for one containing ALL required titles
    (case-insensitive exact match after norm()). Returns (row_index, {title: col}).
    Raises HeaderError with a loud, actionable message if not found — this is the
    mandatory header-title assertion.
    """
    required = [norm(t) for t in required_titles]
    best_missing = None
    best_row = None
    for idx, row in enumerate(rows):
        if idx >= max_scan:
            break
        cells = [norm(c) for c in row]
        pos = {}
        for col, cell in enumerate(cells):
            key = cell.lower()
            if key and key not in pos:  # first occurrence wins
                pos[key] = col
        missing = [t for t in required if t.lower() not in pos]
        if not missing:
            return idx, {t: pos[t.lower()] for t in required}
        if best_missing is None or len(missing) < len(best_missing):
            best_missing, best_row = missing, (idx, cells)
    detail = ""
    if best_row is not None:
        detail = (
            "\nClosest row (index %d) was missing: %s\nIts cells: %s"
            % (best_row[0], ", ".join(repr(m) for m in best_missing), best_row[1])
        )
    raise HeaderError(
        "FATAL: no header row found containing all required column titles: "
        + ", ".join(repr(t) for t in required)
        + ".\nThe workbook layout changed — update the site normalize config before importing."
        + detail
    )


class HeaderError(SystemExit):
    """Raised when the header-title assertion fails. Subclasses SystemExit so an
    un-caught failure aborts the run loudly with a non-zero exit status."""

    def __init__(self, message):
        super().__init__(message)
        self.message = message


def is_url(v):
    """Post-run type check. Empty is valid (optional field); otherwise ^https?://
    and no '@' in the host part (catches pasted emails)."""
    v = norm(v)
    if not v:
        return True
    if not re.match(r"^https?://", v, re.I):
        return False
    host = v.split("//", 1)[-1].split("/", 1)[0]
    return "@" not in host


def spec_required_titles(columns):
    """All source header titles a sheet's column specs reference."""
    titles = []
    for spec in (columns or {}).values():
        src = (spec or {}).get("from")
        if src and src not in titles:
            titles.append(src)
    return titles


def map_row(row_cells, header_map, columns, lookup, split_re=DEFAULT_SPLIT_RE):
    """Map one data row to an output record per the column specs.

    row_cells:  list of raw cells for the row.
    header_map: {source title: column index} from find_header_row().
    columns:    ordered {output_col: spec}. Spec keys:
                  from:   source header title
                  const:  literal value
                  derive: "title" -> hkey of the row's post_title
                  tax:    taxonomy name -> alias-resolve to pipe-joined slugs
                  bool:   True -> truthy cell ("1", "yes", "y", "true", "x") -> "1" else "0"
    lookup:     alias lookup from build_lookup().

    Returns (record dict, warnings list). Record is None when post_title is empty
    (a spacer/section row).
    """

    def cell(title):
        idx = header_map.get(title)
        if idx is None or idx >= len(row_cells):
            return ""
        return norm(row_cells[idx])

    warnings = []
    record = {}

    # post_title first — everything else may depend on it.
    title_spec = columns.get("post_title", {})
    title_val = cell(title_spec.get("from", "")) if "from" in title_spec else norm(title_spec.get("const", ""))
    if not title_val:
        return None, warnings

    for out_col, spec in columns.items():
        spec = spec or {}
        if out_col == "post_title":
            record[out_col] = title_val
            continue
        if "const" in spec:
            record[out_col] = norm(spec["const"])
            continue
        if spec.get("derive") == "title":
            record[out_col] = hkey(title_val)
            continue

        raw = cell(spec.get("from", ""))

        if spec.get("tax"):
            slugs, unmatched = resolve(lookup, spec["tax"], raw, split_re)
            for u in unmatched:
                warnings.append("unmatched %s value '%s' on '%s'" % (spec["tax"], u, title_val[:50]))
            record[out_col] = "|".join(sorted(slugs))
            continue

        if spec.get("bool"):
            record[out_col] = "1" if raw.lower() in ("1", "yes", "y", "true", "x") else "0"
            continue

        record[out_col] = raw

    return record, warnings


def apply_row_rules(record, sheet_cfg, warnings):
    """Post-mapping row rules: draft_when_empty + url_columns sanity check."""
    if record is None:
        return record
    for col in sheet_cfg.get("draft_when_empty", []) or []:
        if "post_status" in record and not record.get(col, ""):
            if record["post_status"] != "draft":
                record["post_status"] = "draft"
                warnings.append(
                    "'%s' set to draft: missing %s" % (record.get("post_title", "?")[:50], col)
                )
    for col in sheet_cfg.get("url_columns", []) or []:
        val = record.get(col, "")
        if not is_url(val):
            warnings.append(
                "MALFORMED URL in '%s' on '%s': %s" % (col, record.get("post_title", "?")[:50], val)
            )
    return record


def upsert_record(records, record, warnings):
    """Merge a mapped record into the keyed collection (identity = title key).

    First non-empty value wins per column; pipe-list columns are unioned. A title
    collision with a DIFFERENT non-empty url gets a loud warning (rows merged,
    first URL kept) — retitle one row at source if they are distinct items.
    """
    if record is None:
        return records
    key = record.get("import_key") or hkey(record.get("post_title", ""))
    prev = records.get(key)
    if prev is None:
        records[key] = dict(record)
        return records
    for col, val in record.items():
        if not val:
            continue
        if not prev.get(col):
            prev[col] = val
        elif "|" in val or "|" in prev[col]:
            merged = sorted(set(filter(None, prev[col].split("|"))) | set(filter(None, val.split("|"))))
            prev[col] = "|".join(merged)
        elif col == "url" and norm(val).lower() != norm(prev[col]).lower():
            warnings.append(
                "TITLE COLLISION (rows merged, first URL kept) on '%s': kept %s — dropped %s. "
                "Retitle one row at source if these are distinct items."
                % (prev.get("post_title", "?")[:50], prev[col], val)
            )
    return records
