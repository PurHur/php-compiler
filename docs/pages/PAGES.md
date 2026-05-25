# GitHub Pages (public status site)

Only **`docs/pages/`** is intended for public visitors. Contributor technical docs stay in the repository and are **not** copied onto this site.

## Excluded from public content (repo-only)

Do **not** link these from `index.html` or `development-status.md` (visitors should use the status narrative, not generated maps):

| Category | Examples |
|----------|----------|
| **Capability matrices** | `docs/capabilities.md`, `docs/capabilities-syntax.md` |
| **Bootstrap inventory tables** | `docs/bootstrap-inventory.md`, `docs/bootstrap-vendor-inventory.md` |
| **CI / gate matrices** | `docs/local-ci-matrix.md`, `docs/miniwebapp-aot-unskip-matrix.md` |
| **Large trackers** | `docs/roadmap-wave3.md` (summarize on the site; link only to GitHub issues) |

Regenerate matrices with `php script/capability-matrix.php` etc. when builtins change — that is **contributor workflow**, not part of the public site update checklist.

## Public URLs

| Page | URL |
|------|-----|
| **Overview** (demo-friendly) | https://purhur.github.io/php-compiler/docs/pages/index.html |
| **Full status** (Jekyll) | https://purhur.github.io/php-compiler/development-status.html |
| **Repository** | https://github.com/PurHur/php-compiler |

## Publish / change source

**Recommended:** Settings → Pages → Deploy from branch **`master`**, folder **`/docs/pages`**.

Do **not** publish `/docs` alone — that would expose bootstrap inventory and capability matrices unintentionally.

## Site map (published files only)

| File | Role |
|------|------|
| `index.html` | Visual overview — progress bars, north stars, demo commands |
| `development-status.md` | **Authoritative written status** |
| `css/style.css`, `js/main.js` | Theme |
| `_layouts/status.html` | Jekyll layout |
| `_config.yml` | `baseurl`, markdown |

## Update workflow (before a demo or release)

1. **`development-status.md`** — milestones, blockers (no links to excluded maps above).
2. **`index.html`** — badges and progress % if the story changed.
3. **`README.md`** / **`docs/GETTING-STARTED.md`** — clone/demo commands; matrices stay in README contributor sections only.

Keep aligned with [#78](https://github.com/PurHur/php-compiler/issues/78), closed [#1044](https://github.com/PurHur/php-compiler/issues/1044) (North Star 1 achieved), [#1492](https://github.com/PurHur/php-compiler/issues/1492).

## Local preview

```bash
cd docs/pages && python3 -m http.server 8765
# or: bundle exec jekyll serve
```

Optional: [`.github/workflows/github-pages.yml`](../../.github/workflows-disabled/github-pages.yml) (currently under `workflows-disabled/`).
