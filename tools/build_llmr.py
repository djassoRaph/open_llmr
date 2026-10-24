#!/usr/bin/env python3
"""Build llmr.json for open-llmr.org with llmr-generator (pinned in README.md).

Repo-only: never upload tools/. Run from anywhere:

    python tools/build_llmr.py

Exits non-zero unless llmr.json and llms.txt link to each other.
"""

import json
import sys
from pathlib import Path

from llmr.generator import build_page, generate
from llmr.llms_txt import links_to_llmr, parse_llms_txt
from llmr.parser import parse_html

DOMAIN = "open-llmr.org"
LLMS_TXT_URL = "https://open-llmr.org/llms.txt"
LLMR_URL = "https://open-llmr.org/llmr.json"

SITE = Path(__file__).resolve().parent.parent

# (file, url, id): exactly the pages the site serves, nothing globbed.
PAGES = [
    ("index.html", "/", "index"),
    ("about.html", "/about.html", "about"),
    ("generate.html", "/generate.html", "generate"),
]


def build():
    pages = []
    home = None
    for name, url, page_id in PAGES:
        parsed = parse_html((SITE / name).read_text(encoding="utf-8"))
        if name == "index.html":
            home = parsed
        page = build_page(parsed, url, page_id)
        page.pop("emb", None)  # hash placeholder, not a real embedding
        if parsed.keywords:  # the page's own meta keywords, not word counts
            page["kw"] = parsed.keywords[:15]
        pages.append(page)

    return generate(
        pages,
        DOMAIN,
        title=home.title,
        description=home.description,
        author_name=home.author,
        llms_txt_url=LLMS_TXT_URL,
    )


def write(data, path):
    text = json.dumps(data, indent=2, ensure_ascii=False) + "\n"
    with open(str(path), "w", encoding="utf-8", newline="\n") as f:
        f.write(text)


def check(data):
    """The pairing: llmr.json -> llms.txt and llms.txt -> llmr.json (under Optional)."""
    errors = []
    site = data.get("s", {})
    if site.get("d") != DOMAIN:
        errors.append("s.d is {!r}, expected {!r}".format(site.get("d"), DOMAIN))
    if site.get("llms_txt") != LLMS_TXT_URL:
        errors.append("s.llms_txt is {!r}, expected {!r}".format(site.get("llms_txt"), LLMS_TXT_URL))

    llms = (SITE / "llms.txt").read_text(encoding="utf-8")
    optional = [l["url"] for l in parse_llms_txt(llms)["links"] if l["section"] == "Optional"]
    if not links_to_llmr(llms) or LLMR_URL not in optional:
        errors.append("llms.txt does not link {} under ## Optional".format(LLMR_URL))
    return errors


def main():
    data = build()
    out = SITE / "llmr.json"
    write(data, out)
    print("Wrote {} ({} pages)".format(out, len(data["p"])))

    errors = check(data)
    for e in errors:
        print("ERROR: " + e, file=sys.stderr)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
