# GitHub Pages (public status site)

Only this folder is published. Repo technical docs (`docs/bootstrap-selfhost.md`, `capabilities.md`, …) stay in the repository and are **not** exposed on the site.

## Enable / fix GitHub Pages

1. **Settings → Pages**
2. Source: **Deploy from a branch**
3. Branch: **`master`**, folder: **`/docs/pages`** (not `/docs`)
4. Save → **https://purhur.github.io/php-compiler/**

**Current deployment:** the repo root is published as Jekyll (entire tree). Public URLs:

| Page | URL |
|------|-----|
| Visual overview | **https://purhur.github.io/php-compiler/docs/pages/index.html** |
| Written status (Jekyll) | **https://purhur.github.io/php-compiler/development-status.html** |
| Repo README (site home) | https://purhur.github.io/php-compiler/ |

If you switch Pages source to **`/docs/pages`** only, the overview moves to **https://purhur.github.io/php-compiler/** and `development-status.html` stays on that subtree.

If you previously used `/docs` alone, prefer publishing from **repo root** (current) or **`/docs/pages`** — not `/docs` — so bootstrap-inventory and other markdown are not exposed unintentionally.

## Site contents

| File | URL | Role |
|------|-----|------|
| `index.html` | `/docs/pages/index.html` (repo-root Jekyll) | Visual overview (progress bars, ladder) |
| `development-status.md` | `/development-status.html` | **Authoritative written status** — edit this when milestones change |
| `css/`, `js/` | assets | PHP-themed styling |

Jekyll renders `development-status.md` with `_layouts/status.html` (same theme as the overview).

## Update workflow

1. Edit **`development-status.md`** for tables, blockers, milestone text.
2. Optionally adjust **`index.html`** for visual summary / progress percentages.
3. Keep aligned with [issue #78](https://github.com/PurHur/php-compiler/issues/78) and README north-star tables.

## Local preview

**Overview (static):**

```bash
cd docs/pages && python3 -m http.server 8765
# http://127.0.0.1:8765/
```

**With Jekyll** (renders `development-status.md`):

```bash
cd docs/pages && bundle exec jekyll serve
# or: docker run --rm -v "$PWD:/site" -p 4000:4000 -w /site jekyll/jekyll jekyll serve
```

## Optional: GitHub Actions

[`.github/workflows/github-pages.yml`](../../.github/workflows/github-pages.yml) deploys `docs/pages` when using **GitHub Actions** as the Pages source.
