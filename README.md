# LLMR — LLM-Readable Site Index

> One file. One fetch. Full site understanding by AI.
## ⚠️ This project has moved to GitLab
**Primary development happens on GitLab:**
### 👉 [gitlab.com/open-llmr](https://gitlab.com/open-llmr)
| Repo | What |
|------|------|
| [spec](https://gitlab.com/open-llmr/spec) | JSON Schema, format specification, validator |
| [python](https://gitlab.com/open-llmr/python) | pip install git+https://gitlab.com/open-llmr/python.git |
| npm | TypeScript (coming soon) |
| wordpress | WP 7.0 plugin (coming soon) |
| laravel | Composer package (coming soon) |
| drupal | Module (coming soon) |
**Why GitLab?** GitHub doesn't support grouping multiple repos under one namespace like GitLab Groups do. Each platform needs its own repo, CI, and contributors.
**Website:** [open-llmr.org](https://open-llmr.org) · **Spec:** [SPEC.md](https://gitlab.com/open-llmr/spec/-/blob/main/SPEC.md) · **License:** MIT

## llms.txt and llmr.json

This site serves both files, and each links to the other. `llms.txt` is written by hand. `llmr.json` is built from `index.html`, `about.html` and `generate.html` by `tools/build_llmr.py`, on top of [llmr-generator](https://gitlab.com/open-llmr/python) 2.1. Rebuild it after adding or changing a page:

```bash
pip install "git+https://gitlab.com/open-llmr/python.git@02f2f6e"
python tools/build_llmr.py
```

The script writes `llmr.json` (UTF-8, LF), leaves `llms.txt` alone, and exits non-zero unless `s.llms_txt` points to `https://open-llmr.org/llms.txt` and `llms.txt` links `https://open-llmr.org/llmr.json` under `## Optional`. A new page must be added to its `PAGES` list. `tools/` is repo-only: don't upload it.

Optional: validate against the schema. This needs a clone of the [spec](https://gitlab.com/open-llmr/spec) repo: `pip install jsonschema`, then `python path/to/spec/validate.py llmr.json` should print `VALID`.
