# Repository documentation

Technical documentation for **php-compiler** contributors and operators. End-user narrative and progress visuals live on **GitHub Pages** (not mirrored from this folder).

## Public site (share this link)

| Page | URL |
|------|-----|
| **Overview** (demo-friendly) | [purhur.github.io/php-compiler/docs/pages/index.html](https://purhur.github.io/php-compiler/docs/pages/index.html) |
| **Full development status** | [purhur.github.io/php-compiler/development-status.html](https://purhur.github.io/php-compiler/development-status.html) |
| **Repository** | [github.com/PurHur/php-compiler](https://github.com/PurHur/php-compiler) |

**Edit sources:** [`pages/development-status.md`](pages/development-status.md) · [`pages/index.html`](pages/index.html) · publish guide [`pages/PAGES.md`](pages/PAGES.md).

## Start here

| Doc | Use when |
|-----|----------|
| [**GETTING-STARTED.md**](GETTING-STARTED.md) | First clone, **demo script**, `phpc` cheat sheet |
| [**../README.md**](../README.md) | Install, CI, north stars, examples table |
| [**pages/development-status.md**](pages/development-status.md) | Milestones, blockers, phase progress (sync to site) |

## Compiler & capabilities

| Doc | Content |
|-----|---------|
| [capabilities.md](capabilities.md) | Builtin matrix (`php script/capability-matrix.php`) |
| [capabilities-syntax.md](capabilities-syntax.md) | Language construct matrix |
| [unsupported-syntax.md](unsupported-syntax.md) | Lint / unsupported registry |
| [stdlib-jit-audit.md](stdlib-jit-audit.md) | Stdlib JIT coverage audit |
| [roadmap-wave3.md](roadmap-wave3.md) | Wave 3 issues #1354–#1379 |
| [runtime-semantics.md](runtime-semantics.md) | Runtime behaviour notes |

## Web deployment & reference app

| Doc | Content |
|-----|---------|
| [deploy-web-aot.md](deploy-web-aot.md) | `phpc build` → `phpc deploy` → CGI |
| [phpc-json.md](phpc-json.md) | Project manifest |
| [miniwebapp-gates.md](miniwebapp-gates.md) | Gate ladder for `003-MiniWebApp` |
| [miniwebapp-aot-unskip-matrix.md](miniwebapp-aot-unskip-matrix.md) | AOT execute bisect matrix |

## Self-host bootstrap

| Doc | Content |
|-----|---------|
| [bootstrap-selfhost.md](bootstrap-selfhost.md) | Gates, waves, stub policy |
| [bootstrap-inventory.md](bootstrap-inventory.md) | `lib/` inventory (`php script/bootstrap-inventory.php`) |
| [bootstrap-vendor-inventory.md](bootstrap-vendor-inventory.md) | Vendor / parser strategy |
| [bootstrap-m5-fast-path.md](bootstrap-m5-fast-path.md) | M5 planning notes |

## CI & tooling

| Doc | Content |
|-----|---------|
| [local-ci-matrix.md](local-ci-matrix.md) | Host / Docker CI matrix |
| [dev/types.md](dev/types.md) | Type system notes |
| [dev/macros.md](dev/macros.md) | Macro / codegen notes |

## Living trackers (GitHub)

- Roadmap umbrella: [#78](https://github.com/PurHur/php-compiler/issues/78)
- North Star 1 (web app): [#1044](https://github.com/PurHur/php-compiler/issues/1044)
- North Star 2 (self-host): [#1056](https://github.com/PurHur/php-compiler/issues/1056)
- Wave 3 batch: [#1380](https://github.com/PurHur/php-compiler/issues/1380)
