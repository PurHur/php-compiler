# GitHub Pages (public status site)

Only **`docs/pages/`** is intended for public visitors. Technical docs (`docs/bootstrap-selfhost.md`, `capabilities.md`, …) stay in the repository and are linked from the site — not copied into Pages.

## Public URLs

| Page | URL |
|------|-----|
| **Overview** (demo-friendly) | https://purhur.github.io/php-compiler/docs/pages/index.html |
| **Full status** (Jekyll) | https://purhur.github.io/php-compiler/development-status.html |
| **Repository** | https://github.com/PurHur/php-compiler |

## Publish / change source

**Recommended (cleanest):** Settings → Pages → Deploy from branch **`master`**, folder **`/docs/pages`**.

Then the overview lives at `https://purhur.github.io/php-compiler/` and `development-status.html` stays on the same site root (`baseurl: /php-compiler` in `_config.yml`).

**Current (repo-root Jekyll):** the whole repo may be published; use the table above for direct links until the source folder is switched.

Do **not** publish `/docs` alone — that would expose bootstrap inventory and other contributor markdown unintentionally.

## Site map

| File | Role |
|------|------|
| `index.html` | Visual overview — progress bars, north stars, **demo commands** |
| `development-status.md` | **Authoritative written status** — edit when milestones change |
| `css/style.css`, `js/main.js` | Theme and animations |
| `_layouts/status.html` | Jekyll layout for `development-status.md` |
| `_config.yml` | Jekyll `baseurl`, markdown |

## Update workflow (before a demo or release)

1. **`development-status.md`** — tables, milestones, blockers (source of truth).
2. **`index.html`** — badges, progress %, demo section if the story changed.
3. **`README.md`** — quick start, “what works today”, links to the site.
4. **`docs/GETTING-STARTED.md`** — presenter commands if the demo path changed.
5. Regenerate capability docs if builtins changed: `php script/capability-matrix.php`, `php script/capability-syntax.php`.

Keep aligned with [#78](https://github.com/PurHur/php-compiler/issues/78), [#1044](https://github.com/PurHur/php-compiler/issues/1044), [#1056](https://github.com/PurHur/php-compiler/issues/1056).

## Local preview

**Static overview:**

```bash
cd docs/pages && python3 -m http.server 8765
# http://127.0.0.1:8765/
```

**With Jekyll** (renders `development-status.md`):

```bash
cd docs/pages && bundle exec jekyll serve
# http://127.0.0.1:4000/php-compiler/development-status.html  (baseurl-dependent)
```

## Optional: GitHub Actions

[`.github/workflows/github-pages.yml`](../../.github/workflows/github-pages.yml) deploys `docs/pages` when Pages source is **GitHub Actions**.
