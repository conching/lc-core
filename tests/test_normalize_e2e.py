#!/usr/bin/env python3
"""LC Core — end-to-end test of tools/normalize_workbook.py.

Builds a tiny fictional workbook in a temp dir, runs the CLI with a JSON config,
and checks the emitted CSV + warnings. Skips (cleanly, exit 0) when openpyxl is
unavailable — the pure logic is covered by test_normalize_lib.py regardless.

Run: python3 tests/test_normalize_e2e.py
"""

import csv
import json
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(HERE, "..", "tools", "normalize_workbook.py")

try:
    import openpyxl
except ImportError:
    print("SKIP: openpyxl not installed; e2e normalize test skipped.")
    sys.exit(0)

CONFIG = {
    "site": "e2e fixture",
    "taxonomy_aliases": {
        "mrfm_day": {"saturday": ["Sat"], "wednesday": ["Wed"]},
    },
    "sheets": [
        {
            "sheet": "Vendors",
            "output": "vendors.csv",
            "columns": {
                "import_key": {"derive": "title"},
                "post_title": {"from": "Vendor Name"},
                "post_status": {"const": "publish"},
                "url": {"from": "Website"},
                "featured": {"from": "Featured?", "bool": True},
                "mrfm_day": {"from": "Market Days", "tax": "mrfm_day"},
            },
            "draft_when_empty": ["url"],
            "url_columns": ["url"],
        }
    ],
}


def build_workbook(path):
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Vendors"
    ws.append(["VENDORS — season 2026", "", "", "", ""])  # banner row (skipped)
    ws.append(["#", "Vendor Name", "Website", "Featured?", "Market Days"])
    ws.append([1, "Maple Ridge Honey", "https://example.org/honey", "yes", "Sat; Wed"])
    ws.append([2, "Blue Barn Bakery", "", "", "Sat"])                      # no URL -> draft
    ws.append([3, "Maple  Ridge   Honey", "https://example.org/honey2", "", ""])  # title drift -> merges
    ws.append([4, "Fiddlehead Farm", "fiddlehead.example", "", "Blursday"])  # bad URL + unknown day
    ws.append([5, "Formula Farm", "=HYPERLINK(\"https://bad.example\")", "", "Sat"])
    ws["C7"].data_type = "s"  # text beginning '=' rather than an evaluated workbook formula
    wb.save(path)


def main():
    failures = 0
    with tempfile.TemporaryDirectory() as tmp:
        wb_path = os.path.join(tmp, "fixture.xlsx")
        cfg_path = os.path.join(tmp, "config.json")
        out_dir = os.path.join(tmp, "out")
        build_workbook(wb_path)
        with open(cfg_path, "w", encoding="utf-8") as f:
            json.dump(CONFIG, f)

        # --- happy path ---
        res = subprocess.run(
            [sys.executable, TOOL, wb_path, cfg_path, "--out", out_dir],
            capture_output=True, text=True,
        )
        def check(label, cond, detail=""):
            nonlocal failures
            if cond:
                print("  ok    " + label)
            else:
                failures += 1
                print("  FAIL  " + label + ("  [" + detail + "]" if detail else ""))

        check("tool exits 0", res.returncode == 0, res.stdout + res.stderr)
        check("header validated message", "header validated" in res.stdout, res.stdout)

        rows = {}
        with open(os.path.join(out_dir, "vendors.csv"), encoding="utf-8") as f:
            for r in csv.DictReader(f):
                rows[r["post_title"]] = r

        check("title-drift rows merged (4 unique vendors)", len(rows) == 4, str(sorted(rows)))
        honey = rows.get("Maple Ridge Honey", {})
        check("first URL kept on merge", honey.get("url") == "https://example.org/honey", str(honey))
        check("key derived from title only", honey.get("import_key") == __import__("hashlib").md5(b"maple ridge honey").hexdigest()[:12], str(honey.get("import_key")))
        check("bool coerced", honey.get("featured") == "1", str(honey))
        check("tax slugs pipe-joined sorted", honey.get("mrfm_day") == "saturday|wednesday", str(honey.get("mrfm_day")))
        check("missing URL -> draft", rows.get("Blue Barn Bakery", {}).get("post_status") == "draft", str(rows.get("Blue Barn Bakery")))

        with open(os.path.join(out_dir, "normalization-warnings.txt"), encoding="utf-8") as f:
            warns = f.read()
        check("TITLE COLLISION warned", "TITLE COLLISION" in warns, warns)
        check("malformed URL warned", "MALFORMED URL" in warns, warns)
        check("unmatched tax value warned", "Blursday" in warns, warns)

        # --- explicit spreadsheet-safe export path ---
        safe_dir = os.path.join(tmp, "safe-out")
        res_safe = subprocess.run(
            [sys.executable, TOOL, wb_path, cfg_path, "--out", safe_dir, "--spreadsheet-safe"],
            capture_output=True, text=True,
        )
        check("spreadsheet-safe export exits 0", res_safe.returncode == 0, res_safe.stdout + res_safe.stderr)
        with open(os.path.join(safe_dir, "vendors.csv"), encoding="utf-8") as f:
            safe_rows = {r["post_title"]: r for r in csv.DictReader(f)}
        check(
            "formula-like cell is escaped for spreadsheet opening",
            safe_rows.get("Formula Farm", {}).get("url", "").startswith("'="),
            str(safe_rows.get("Formula Farm")),
        )

        # --- header-assertion path: config expects a column the sheet lacks ---
        bad_cfg = json.loads(json.dumps(CONFIG))
        bad_cfg["sheets"][0]["columns"]["post_title"]["from"] = "Inserted Column"
        bad_path = os.path.join(tmp, "bad-config.json")
        with open(bad_path, "w", encoding="utf-8") as f:
            json.dump(bad_cfg, f)
        res2 = subprocess.run(
            [sys.executable, TOOL, wb_path, bad_path, "--out", out_dir],
            capture_output=True, text=True,
        )
        check("missing header title fails loudly (exit != 0)", res2.returncode != 0)
        check("failure names the missing title", "Inserted Column" in (res2.stderr + res2.stdout), res2.stderr)

    print("\n%s" % ("ALL E2E CHECKS PASSED" if failures == 0 else "%d E2E FAILURE(S)" % failures))
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
