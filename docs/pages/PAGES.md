# GitHub Pages (public status site)

Only **`docs/pages/`** is published. Contributor docs stay in the repo root `docs/` tree.

## Public URLs

| Page | URL |
|------|-----|
| **Overview** | https://purhur.github.io/php-compiler/docs/pages/index.html |
| **Status** (short) | https://purhur.github.io/php-compiler/development-status.html |
| **Missing implementation** (gap tables) | https://purhur.github.io/php-compiler/docs/pages/missing-implementation.html |
| **PHP capability comparison** | https://purhur.github.io/php-compiler/docs/pages/capability-comparison.html |
| **Repository** | https://github.com/PurHur/php-compiler |

## Site map

| File | Role |
|------|------|
| `index.html` | Landing — demo, progress, north star summary |
| `development-status.md` | Short status narrative (Jekyll) |
| `missing-implementation.html` | Tables of real open implementation gaps |
| `capability-comparison.html` | PHP language/stdlib vs VM / JIT / AOT (generated) |
| `css/style.css`, `js/main.js` | Theme |
| `_layouts/status.html` | Jekyll layout for status markdown |

## Not on the public site

Capability matrices, bootstrap inventory tables, CI gate matrices, and long contributor runbooks — see [docs/README.md](https://github.com/PurHur/php-compiler/blob/master/docs/README.md) on GitHub.

## Update checklist

1. **`missing-implementation.html`** — when a gap closes or a new blocker is user-visible
2. **`capability-comparison.html`** — after matrix regen: `php script/generate-pages-capability-comparison.php`
3. **`development-status.md`** — milestone / ladder changes
4. **`index.html`** — badges and progress % if the story changed
5. **`README.md`** — link to status site

Trackers: [#78](https://github.com/PurHur/php-compiler/issues/78), [#1492](https://github.com/PurHur/php-compiler/issues/1492).

## Publish

GitHub Pages uses **legacy Jekyll** from the **repo root** (`Settings → Pages → branch `master`, folder `/`). The repo-root [`_config.yml`](../../_config.yml) scopes the build to `docs/pages/` only (excluding `build/`, `prelinked/`, contributor docs, etc.). Without it, Jekyll publishes the whole tree and deploy fails.

Local preview (static, no Jekyll):

```bash
cd docs/pages && python3 -m http.server 8765
```
