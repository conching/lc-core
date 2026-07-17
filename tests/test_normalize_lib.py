#!/usr/bin/env python3
"""LC Core — unit tests for tools/normalize_lib.py (no openpyxl, no workbook).

Run: python3 tests/test_normalize_lib.py
"""

import hashlib
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "tools"))

from normalize_lib import (
    HeaderError,
    apply_row_rules,
    build_lookup,
    find_header_row,
    hkey,
    is_url,
    map_row,
    norm,
    resolve,
    spec_required_titles,
    spreadsheet_safe,
    upsert_record,
)


class TestNorm(unittest.TestCase):
    def test_collapse_whitespace(self):
        self.assertEqual(norm("Crème  Brûlée\tCo "), "Crème Brûlée Co")

    def test_curly_quotes_fold_to_okina(self):
        self.assertEqual(norm("Brûlée’s"), "Brûléeʻs")
        self.assertEqual(norm("Brûlée‘s"), "Brûléeʻs")

    def test_none_is_empty(self):
        self.assertEqual(norm(None), "")

    def test_spreadsheet_safe_formula_prefixes(self):
        self.assertEqual(spreadsheet_safe("=1+1"), "'=1+1")
        self.assertEqual(spreadsheet_safe("+18085551212"), "'+18085551212")
        self.assertEqual(spreadsheet_safe("ordinary"), "ordinary")


class TestHkey(unittest.TestCase):
    """Identity = TITLE ONLY (the 2026-07-09 26-duplicate incident)."""

    def test_known_digest_matches_php_spec(self):
        # Same digest the PHP test pins: md5('maple ridge market')[:12]
        expected = hashlib.md5(b"maple ridge market").hexdigest()[:12]
        self.assertEqual(hkey("Maple Ridge Market"), expected)

    def test_url_is_ignored(self):
        # Correcting a URL must NEVER change identity.
        self.assertEqual(hkey("Red Cross", "https://old.example"), hkey("Red Cross", "https://new.example"))
        self.assertEqual(hkey("Red Cross"), hkey("Red Cross", "anything"))

    def test_case_and_whitespace_insensitive(self):
        self.assertEqual(hkey("RED  CROSS"), hkey("red cross"))

    def test_empty(self):
        self.assertEqual(hkey("   "), "")


ALIASES = {
    "mrfm_category": {
        "produce": ["Vegetables", "Veggies", "Fruit & Veg"],
        "baked-goods": ["Bakery"],
        "": ["misc", "tbd"],
    },
    "mrfm_day": {"saturday": ["Sat"], "wednesday": ["Wed", "Midweek"]},
}


class TestResolve(unittest.TestCase):
    def setUp(self):
        self.lookup = build_lookup(ALIASES)

    def test_alias_resolution(self):
        slugs, unmatched = resolve(self.lookup, "mrfm_category", "Veggies")
        self.assertEqual(slugs, ["produce"])
        self.assertEqual(unmatched, [])

    def test_slug_itself_is_valid(self):
        slugs, _ = resolve(self.lookup, "mrfm_category", "baked-goods")
        self.assertEqual(slugs, ["baked-goods"])

    def test_hyphen_to_space_form(self):
        slugs, _ = resolve(self.lookup, "mrfm_category", "Baked Goods")
        self.assertEqual(slugs, ["baked-goods"])

    def test_multi_value_split_and_dedupe(self):
        slugs, unmatched = resolve(self.lookup, "mrfm_day", "Sat; Wed / saturday")
        self.assertEqual(slugs, ["saturday", "wednesday"])
        self.assertEqual(unmatched, [])

    def test_empty_slug_is_silent_noop(self):
        slugs, unmatched = resolve(self.lookup, "mrfm_category", "misc")
        self.assertEqual(slugs, [])
        self.assertEqual(unmatched, [])

    def test_unknown_value_reported(self):
        slugs, unmatched = resolve(self.lookup, "mrfm_category", "Quantum Kale")
        self.assertEqual(slugs, [])
        self.assertEqual(unmatched, ["Quantum Kale"])


class TestHeaderAssertion(unittest.TestCase):
    """The mandatory fail-loudly guard (positional drift corrupted two imports)."""

    ROWS = [
        ["VENDORS — 2026 season", "", ""],
        ["#", "Vendor Name", "Category", "Website"],
        ["1", "Maple Ridge Honey", "Produce", "https://example.org"],
    ]

    def test_finds_header_and_maps_titles(self):
        idx, cmap = find_header_row(self.ROWS, ["Vendor Name", "Website"])
        self.assertEqual(idx, 1)
        self.assertEqual(cmap, {"Vendor Name": 1, "Website": 3})

    def test_case_insensitive(self):
        idx, cmap = find_header_row(self.ROWS, ["vendor name"])
        self.assertEqual(cmap["vendor name"], 1)

    def test_missing_title_fails_loudly(self):
        with self.assertRaises(HeaderError) as ctx:
            find_header_row(self.ROWS, ["Vendor Name", "Inserted Column"])
        self.assertIn("Inserted Column", str(ctx.exception))
        # HeaderError must abort the run (SystemExit subclass, non-zero payload).
        self.assertIsInstance(ctx.exception, SystemExit)

    def test_spec_required_titles(self):
        cols = {
            "import_key": {"derive": "title"},
            "post_title": {"from": "Vendor Name"},
            "post_status": {"const": "publish"},
            "url": {"from": "Website"},
        }
        self.assertEqual(spec_required_titles(cols), ["Vendor Name", "Website"])


COLUMNS = {
    "import_key": {"derive": "title"},
    "post_title": {"from": "Vendor Name"},
    "post_status": {"const": "publish"},
    "url": {"from": "Website"},
    "featured": {"from": "Featured?", "bool": True},
    "vendor_category": {"from": "Category"},
    "mrfm_day": {"from": "Market Days", "tax": "mrfm_day"},
}
HEADER_MAP = {"Vendor Name": 1, "Website": 3, "Featured?": 4, "Category": 2, "Market Days": 5}


class TestMapRow(unittest.TestCase):
    def setUp(self):
        self.lookup = build_lookup(ALIASES)

    def test_full_row(self):
        row = ["1", "Maple Ridge Honey", "Produce", "https://example.org", "yes", "Sat; Wed"]
        record, warnings = map_row(row, HEADER_MAP, COLUMNS, self.lookup)
        self.assertEqual(record["post_title"], "Maple Ridge Honey")
        self.assertEqual(record["import_key"], hkey("Maple Ridge Honey"))
        self.assertEqual(record["post_status"], "publish")
        self.assertEqual(record["url"], "https://example.org")
        self.assertEqual(record["featured"], "1")
        self.assertEqual(record["vendor_category"], "Produce")  # free text passes through
        self.assertEqual(record["mrfm_day"], "saturday|wednesday")
        self.assertEqual(warnings, [])

    def test_empty_title_skips_row(self):
        record, warnings = map_row(["", "", "", "", "", ""], HEADER_MAP, COLUMNS, self.lookup)
        self.assertIsNone(record)

    def test_short_row_padded(self):
        record, _ = map_row(["1", "Solo Vendor"], HEADER_MAP, COLUMNS, self.lookup)
        self.assertEqual(record["url"], "")
        self.assertEqual(record["featured"], "0")

    def test_unmatched_tax_value_warns(self):
        row = ["1", "V", "", "", "", "Blursday"]
        record, warnings = map_row(row, HEADER_MAP, COLUMNS, self.lookup)
        self.assertEqual(record["mrfm_day"], "")
        self.assertEqual(len(warnings), 1)
        self.assertIn("Blursday", warnings[0])


class TestRowRulesAndValidation(unittest.TestCase):
    def test_is_url(self):
        self.assertTrue(is_url("https://example.org/a"))
        self.assertTrue(is_url(""))  # empty optional field is fine
        self.assertFalse(is_url("example.org"))
        self.assertFalse(is_url("https://user@example.org"))  # pasted email-ish

    def test_url_column_check_warns(self):
        warnings = []
        record = {"post_title": "V", "post_status": "publish", "url": "not-a-url"}
        apply_row_rules(record, {"url_columns": ["url"]}, warnings)
        self.assertEqual(len(warnings), 1)
        self.assertIn("MALFORMED URL", warnings[0])

    def test_draft_when_empty(self):
        warnings = []
        record = {"post_title": "V", "post_status": "publish", "url": ""}
        apply_row_rules(record, {"draft_when_empty": ["url"]}, warnings)
        self.assertEqual(record["post_status"], "draft")

    def test_valid_row_no_warnings(self):
        warnings = []
        record = {"post_title": "V", "post_status": "publish", "url": "https://example.org"}
        apply_row_rules(record, {"url_columns": ["url"], "draft_when_empty": ["url"]}, warnings)
        self.assertEqual(record["post_status"], "publish")
        self.assertEqual(warnings, [])


class TestUpsert(unittest.TestCase):
    def test_merge_first_nonempty_wins(self):
        records, warnings = {}, []
        upsert_record(records, {"import_key": "k1", "post_title": "V", "summary": "First"}, warnings)
        upsert_record(records, {"import_key": "k1", "post_title": "V", "summary": "Second", "url": "https://x.org"}, warnings)
        self.assertEqual(len(records), 1)
        self.assertEqual(records["k1"]["summary"], "First")
        self.assertEqual(records["k1"]["url"], "https://x.org")

    def test_pipe_lists_union(self):
        records, warnings = {}, []
        upsert_record(records, {"import_key": "k1", "post_title": "V", "mrfm_day": "saturday"}, warnings)
        upsert_record(records, {"import_key": "k1", "post_title": "V", "mrfm_day": "wednesday|saturday"}, warnings)
        self.assertEqual(records["k1"]["mrfm_day"], "saturday|wednesday")

    def test_title_collision_different_url_warns(self):
        records, warnings = {}, []
        upsert_record(records, {"import_key": "k1", "post_title": "V", "url": "https://a.org"}, warnings)
        upsert_record(records, {"import_key": "k1", "post_title": "V", "url": "https://b.org"}, warnings)
        self.assertEqual(records["k1"]["url"], "https://a.org")  # first URL kept
        self.assertTrue(any("TITLE COLLISION" in w for w in warnings))


if __name__ == "__main__":
    unittest.main(verbosity=2)
