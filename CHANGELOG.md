# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## v1.1.0 (unreleased)

User release v1.1.0 ([#8739](https://github.com/PurHur/php-compiler/issues/8739)) — M5 fast-path stability, stdlib/JIT parity batch, and release-readiness gates since [v1.0.0](https://github.com/PurHur/php-compiler/releases/tag/v1.0.0).

### Added

- `script/release-readiness.sh` quick/full presenter for daily release review ([#8737](https://github.com/PurHur/php-compiler/issues/8737)).
- M5 fast development gate: `make north-star5-verify-fast` and `make bootstrap-selfhost-vm-driver-execute-probe` as default iteration path ([#8683](https://github.com/PurHur/php-compiler/issues/8683)).
- JIT/stdlib work since v1.0.0: `preg_match` / `preg_match_all`, `spl_autoload*`, enum strict builtins, PHP-in-PHP `proc_open` paths.

### Changed

- Bootstrap inventory and spine coverage (2744/2744 Phase A at 2026-06-16); prelinked gen-0/vendor blobs for fast M5 verification.
- Docs, capability matrices, and examples 000–009 ladder synced (sessions, file upload, throws web, self-host probe).

### Fixed

- `php-types-never-type.patch` on fresh `composer install` ([#8738](https://github.com/PurHur/php-compiler/issues/8738)).
- Spine AOT/JIT blockers and inventory driver refresh ([#8559](https://github.com/PurHur/php-compiler/issues/8559), [#8683](https://github.com/PurHur/php-compiler/issues/8683)).
- MiniWebApp AOT: nested layout `$_REQUEST` reads, POST body env in AOT harness, per-method include/script-global scope ([#878](https://github.com/PurHur/php-compiler/issues/878), [#764](https://github.com/PurHur/php-compiler/issues/764)).
- AOT multipart file upload: LF header lines in `__phpc_multipart_find_header_value`, null-safe `Content-Type` in `__phpc_multipart_set_file_entry` ([#878](https://github.com/PurHur/php-compiler/issues/878), 006-FileUploadWeb).

### Release gates

```bash
./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --json'
./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --full --json'
```

See roadmap [#78](https://github.com/PurHur/php-compiler/issues/78).

## v1.0.0 — 2026-05-29

First maintained stable release: VM + AOT for a web-capable PHP subset, reference examples 000–007, experimental self-host path.

[Unreleased]: https://github.com/PurHur/php-compiler/compare/v1.0.0...master
[v1.0.0]: https://github.com/PurHur/php-compiler/releases/tag/v1.0.0
