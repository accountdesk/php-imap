#!/usr/bin/env python3
"""Markdown summary of PHPUnit runs for $GITHUB_STEP_SUMMARY.

Usage: phpunit-summary.py <title> <label>=<junit.xml>[:<phpunit-output.txt>] ...

Counts and failures come from the JUnit report; skip reasons from the PHPUnit text output (PHPUnit 9 does
not write them into JUnit). A missing report is listed as such (the run crashed before writing it).
"""

import re
import sys
import xml.etree.ElementTree as ET


def first_line(text, skip="", limit=200):
    for line in (text or "").strip().splitlines():
        line = line.strip()
        if line and line != skip:
            return line if len(line) <= limit else line[:limit] + "..."
    return ""


def skipped_reasons(path):
    """Skipped tests with their reason from the 'There were N skipped tests:' block of the text output."""
    try:
        with open(path, encoding="utf-8", errors="replace") as f:
            text = re.sub(r"\x1b\[[0-9;]*m", "", f.read())
    except OSError:
        return []
    match = re.search(r"There (?:was|were) \d+ skipped tests?:\n(.*?)(?:\n(?:OK|ERRORS|FAILURES|Tests:)|\Z)", text, re.S)
    if not match:
        return []
    reasons = []
    for entry in re.split(r"\n(?=\d+\) )", match.group(1).strip()):
        lines = [line for line in entry.splitlines() if line.strip()]
        if lines:
            name = re.sub(r"^\d+\) ", "", lines[0])
            reasons.append((name, lines[1].strip() if len(lines) > 1 else ""))
    return reasons


def main():
    title, runs = sys.argv[1], sys.argv[2:]
    rows, failures, skips = [], [], []
    for run in runs:
        label, _, files = run.partition("=")
        junit, _, output = files.partition(":")
        try:
            root = ET.parse(junit).getroot()
        except (OSError, ET.ParseError) as e:
            rows.append(f"| {label} | - | - | - | - | - | - | :x: no report ({type(e).__name__}) |")
            continue
        suite = root if root.tag == "testsuite" else root.find("testsuite")
        attr = suite.attrib if suite is not None else {}
        tests = int(attr.get("tests", 0))
        failed = int(attr.get("failures", 0))
        errors = int(attr.get("errors", 0))
        skipped = int(attr.get("skipped", 0))
        passed = tests - failed - errors - skipped
        state = ":white_check_mark:" if failed + errors == 0 else ":x:"
        rows.append(f"| {label} | {tests} | {passed} | {failed} | {errors} | {skipped} | {float(attr.get('time', 0)):.0f} s | {state} |")
        for case in root.iter("testcase"):
            for kind in ("failure", "error"):
                node = case.find(kind)
                if node is not None:
                    name = f"{case.get('class', case.get('classname', ''))}::{case.get('name')}"
                    failures.append(f"- **{label}** `{name}` ({kind}): {first_line(node.text, name) or node.get('type', '')}")
        if output:
            skips += [f"- **{label}** `{name}`: {reason}" for name, reason in skipped_reasons(output)]

    print(f"### {title}\n")
    print("| Target | Tests | Passed | Failures | Errors | Skipped | Time | |")
    print("|---|---:|---:|---:|---:|---:|---:|---|")
    print("\n".join(rows))
    if failures:
        print(f"\n<details><summary>Failures and errors ({len(failures)})</summary>\n")
        print("\n".join(failures))
        print("\n</details>")
    if skips:
        print(f"\n<details><summary>Skipped ({len(skips)})</summary>\n")
        print("\n".join(skips))
        print("\n</details>")
    print()


if __name__ == "__main__":
    main()
