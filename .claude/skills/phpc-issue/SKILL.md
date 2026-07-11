---
name: phpc-issue
description: File a php-compiler GitHub issue in house format — title conventions, category taxonomy, labels, repro table, php-src reference, Done-when checklist. Use whenever creating or triaging issues on PurHur/php-compiler.
---

# php-compiler issue conventions

## Title format

`<Category>: <symptom, present tense> — <key detail> (<php-src file or repo path>)`

Categories seen in the tracker: `Regression:` (worked before / diverges from Zend), `Stdlib:` (missing builtin), `Language:` (syntax/semantics vs Zend), `Foundation:` (DevEx, deps, gates), `Tooling:`, `Gen-1+ #N:` (bootstrap ladder). Reference prior issues inline as `(re-#NNNN)` when respinning.

## Body template

```markdown
## Category
`<category>` · <sub-tag, e.g. php-src-strict / doc-sync gate>

## Problem
<2–4 sentences. For behavior gaps: a table comparing Zend vs VM/JIT/AOT output, with date.>

| Repro | Zend 8.2+ | VM (<date>) |
|-------|-----------|-------------|
| `<snippet>` | `<expected>` | `<actual>` |

## php-src reference
- [php/php-src `<file>`](https://github.com/php/php-src/blob/master/<file>) — <function/handler>

## PHP implementation target
- `<repo file/path>` — <what to change>; compile-time guard in PHP lowering only where possible

## Repro
```bash
./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/<file>.php'
```

## Done when
- [ ] <observable outcome on VM/JIT/AOT>
- [ ] Compliance `.phpt` guard under `test/compliance/cases/<area>/`
- [ ] php-src-strict; no php-compiler-strict shortcut
```

## Labels

Always: `bug` or `enhancement` + one `area:*` (`area:compiler`, `area:vm`, `area:web`, `area:tooling`) + one `phase-*` (`phase-0:Foundation` … `phase-5:reference-app`). Bootstrap-ladder items add `m2-spine-unit` where applicable.

## Rules

- One defect per issue; respins get a fresh issue with `(re-#NNNN)`.
- Include the exact commit SHAs that introduced drift when known (`git log --oneline -- <path>`).
- Repro commands must run via `docker-exec.sh` so any agent can reproduce without host setup.
- CONTRIBUTING forbids drive-by issues — file only as/for the maintainer within the agreed agent workflow.
