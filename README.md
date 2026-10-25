# LLMR — LLM-Readable Site Index

> One file. One fetch. Full site understanding by AI.
## ⚠️ This project has moved to GitLab
**Primary development happens on GitLab:**
### 👉 [gitlab.com/open-llmr](https://gitlab.com/open-llmr)
| Repo | What |
|------|------|
| [spec](https://gitlab.com/open-llmr/spec) | JSON Schema, format specification, validator |
| [python](https://gitlab.com/open-llmr/python) | `pip install "git+https://gitlab.com/open-llmr/python.git"`: `llmr generate`, `llmr read` (2.3) |
| [npm](https://gitlab.com/open-llmr/npm) | TypeScript, LLMR v2.1 on GitLab (not on npm yet) |
| [wordpress](https://gitlab.com/open-llmr/wordpress) | WP 6.7+ plugin, LLMR v2.1 on GitLab (not on wordpress.org yet) |
| laravel | Composer package (coming soon) |
| drupal | Module (coming soon) |
**Why GitLab?** GitHub doesn't support grouping multiple repos under one namespace like GitLab Groups do. Each platform needs its own repo, CI, and contributors.
**Website:** [open-llmr.org](https://open-llmr.org) · **Spec:** [SPEC.md](https://gitlab.com/open-llmr/spec/-/blob/main/SPEC.md) · **License:** MIT

## llms.txt and llmr.json

This site serves both files, and each links to the other. `llms.txt` is written by hand. `llmr.json` is built from `index.html`, `about.html` and `generate.html` by `tools/build_llmr.py`, on top of [llmr-generator](https://gitlab.com/open-llmr/python) 2.3. Rebuild it after adding or changing a page:

```bash
pip install "llmr-generator[validate] @ git+https://gitlab.com/open-llmr/python.git@d0cc3c98bded8885aa17da6c213ed619081caf40"
python tools/build_llmr.py
llmr validate llmr.json
```

The script writes `llmr.json` (UTF-8, LF), leaves `llms.txt` alone, and exits non-zero unless `s.llms_txt` points to `https://open-llmr.org/llms.txt` and `llms.txt` links `https://open-llmr.org/llmr.json` under `## Optional`. A new page must be added to its `PAGES` list. `tools/` is repo-only: don't upload it.

`llmr validate` uses the schema bundled with the package and should print `VALID`.

The online generator (`config/generate.php`) refuses sites hosted on this server's own address, open-llmr.org included. That's on purpose: requests from the server's own IP count as "local" to Apache. Use the CLI for those sites. Its test harness runs with `php8.3 tools/test_generate.php` and `node tools/test_generate_js.mjs`.
