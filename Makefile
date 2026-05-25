#!make
# Primary dev image: make docker-build-22 → php-compiler:22.04-dev (issues #73, #202).
# Legacy ircmaxell/php-compiler:* targets below are deprecated (Docker Hub 404).

.PHONY: composer-install
composer-install:
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev composer install --no-ansi --no-interaction --no-progress
	#docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev php vendor/pre/plugin/source/environment.php
	#docker run -v $(shell pwd):/compiler --entrypoint "/usr/bin/patch" ircmaxell/php-compiler:16.04-dev -p0 -d /compiler/vendor/pre/plugin/hidden/vendor/yay/yay/src -i /compiler/Docker/yaypatch.patch

.PHONY: composer-update
composer-update:
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev composer update --no-ansi --no-interaction --no-progress

.PHONY: shell
shell:
	docker run -it --cap-add=SYS_PTRACE --security-opt seccomp=unconfined -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev /bin/bash

.PHONY: shell-18
shell-18:
	docker run -it --cap-add=SYS_PTRACE --security-opt seccomp=unconfined -v $(shell pwd):/compiler ircmaxell/php-compiler:18.04-dev /bin/bash

.PHONY: docker-build-clean
docker-build-clean:
	docker build --no-cache -t ircmaxell/php-compiler:16.04-dev Docker/dev/ubuntu-16.04
	docker build --no-cache -t ircmaxell/php-compiler:16.04 -f Docker/ubuntu-16.04/Dockerfile .

.PHONY: docker-build
docker-build:
	docker build -t ircmaxell/php-compiler:16.04-dev Docker/dev/ubuntu-16.04
	docker build --no-cache -t ircmaxell/php-compiler:16.04 -f Docker/ubuntu-16.04/Dockerfile .

.PHONY: docker-build-clean-18
docker-build-clean-18:
	docker build --no-cache -t ircmaxell/php-compiler:18.04-dev Docker/dev/ubuntu-18.04
	docker build --no-cache -t ircmaxell/php-compiler:18.04 -f Docker/ubuntu-18.04/Dockerfile .

.PHONY: docker-build-18
docker-build-18:
	docker build -t ircmaxell/php-compiler:18.04-dev Docker/dev/ubuntu-18.04
	docker build --no-cache -t ircmaxell/php-compiler:18.04 -f Docker/ubuntu-18.04/Dockerfile .

.PHONY: benchmark
benchmark: rebuild-changed
	docker run -v $(shell pwd):/compiler --entrypoint php -e PHP_7_4=php ircmaxell/php-compiler:16.04 script/bench.php

.PHONY: build
build: composer-install rebuild fix rebuild-examples

.PHONY: rebuild
rebuild:
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev php script/rebuild.php

.PHONY: rebuild-changed
rebuild-changed:
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev php script/rebuild.php onlyChanged

.PHONY: rebuild-examples
rebuild-examples:
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev php script/rebuild-examples.php

# Host: refresh examples/README.md benchmark table (requires LLVM for AOT columns)
.PHONY: bench
bench:
	./script/php-local.sh script/rebuild-examples.php

.PHONY: fix
fix:
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev php vendor/bin/php-cs-fixer fix --allow-risky=yes

.PHONY: phan
phan:
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev php vendor/bin/phan

# Default CI: Ubuntu 22.04 + PHP 8.2 dev image (issues #73, #249). Uses bind-mount or tar fallback.
.PHONY: test
test: docker-build-22
	./script/docker-ci-local.sh

# Deprecated: PHP 7.4 on Ubuntu 16.04 (ircmaxell/php-compiler:16.04-dev image often unavailable).
.PHONY: test-legacy-16
test-legacy-16: rebuild-changed
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:16.04-dev php vendor/bin/phpunit

# Run the full PHPUnit suite on the host PHP (no Docker). Requires composer install.
.PHONY: test-local test-fast test-fast-bootstrap test-fast-jit-preflight test-docker-fast test-docker-fast-jit-preflight
test-local:
	./script/ci-local.sh

# Fast CI: VM/compliance only — no JIT/AOT compile (issue #436).
test-fast:
	./script/ci-fast.sh

# Fast CI with optional bootstrap tail (aot-lint + probe + wave-check when LLVM present).
test-fast-bootstrap:
	CI_FAST_BOOTSTRAP=1 ./script/ci-fast.sh

# Fast CI with optional JIT bootstrap preflight (issue #728; LLVM present → MCJIT probe).
test-fast-jit-preflight:
	JIT_PREFLIGHT_GATE=1 ./script/ci-fast.sh

test-docker-fast: docker-build-22
	./script/docker-ci-local.sh fast

test-docker-fast-jit-preflight: docker-build-22
	JIT_PREFLIGHT_GATE=1 ./script/docker-ci-local.sh fast

# VM smoke: examples/001-SimpleWeb with ?name=Test
.PHONY: web-smoke miniwebapp-gates miniwebapp-aot-bisect north-star1-verify north-star2-verify
web-smoke:
	./script/web-smoke.sh

# MiniWebApp CI gate ladder status (issue #503; no full CI)
miniwebapp-gates:
	./script/miniwebapp-gates.sh

# Example web integration verify (legacy target name north-star1-verify; issue #1845)
north-star1-verify:
	./script/north-star1-verify.sh

north-star2-verify:
	./script/north-star2-verify.sh

# Ordered #764 AOT PHPT ladder (issue #879; requires LLVM 9)
miniwebapp-aot-bisect:
	./script/miniwebapp-aot-bisect.sh

# HTTP smoke: phpc serve + curl for 001-SimpleWeb and 002-StaticWeb (issue #298)
.PHONY: examples-web-smoke examples-sessions-smoke examples-throws-smoke examples-fileupload-deploy-smoke examples-web-smoke-prebuild examples-aot-smoke deploy-smoke deploy-smoke-all
examples-web-smoke:
	./script/examples-web-smoke.sh

examples-sessions-smoke:
	./script/examples-web-smoke.sh --sessions-only

examples-throws-smoke:
	./script/examples-web-smoke.sh --throws-only

# 006-FileUploadWeb deploy CGI only (FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1; issue #2044)
examples-fileupload-deploy-smoke:
	./script/examples-fileupload-deploy-smoke.sh

examples-web-smoke-prebuild:
	./script/examples-web-smoke-prebuild.sh

# AOT build + CLI execute for 000-004 (issue #667); skips when LLVM missing
# Slice: EXAMPLES_AOT_SMOKE_ONLY=007 THROWSWEB_AOT_SMOKE_GATE=1 (#2104)
examples-aot-smoke:
	./script/examples-aot-smoke.sh

# phpc deploy + PHPC_DEPLOY_ROOT CGI smoke for 001/002 (issue #718); skips when LLVM missing
# DEPLOY_SMOKE_ALL=1 runs full ladder with skip reasons for 005/006 (#2077)
deploy-smoke:
	@if [ "$${DEPLOY_SMOKE_ALL:-0}" = "1" ]; then ./script/deploy-smoke-all.sh; else \
		./script/deploy-smoke.sh --example 001; \
		./script/deploy-smoke.sh --example 002; \
		if [ "$${DEPLOY_SMOKE_003_EXECUTE:-1}" = "1" ]; then ./script/deploy-smoke.sh --example 003; fi; \
		if [ "$${SESSIONS_WEB_DEPLOY_SMOKE_GATE:-0}" = "1" ]; then ./script/deploy-smoke.sh --example 005; fi; \
		if [ "$${FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE:-0}" = "1" ]; then ./script/deploy-smoke.sh --example 006; fi; \
	fi

# Full deploy ladder 001–006 with explicit skip messages when gates=0 (#2077)
deploy-smoke-all:
	./script/deploy-smoke-all.sh

# Local HTTP dev server (see bin/serve.php)
SERVE_ADDR ?= 127.0.0.1:8080
SERVE_ROOT ?= examples/001-SimpleWeb
.PHONY: serve
serve:
	./phpc serve $(SERVE_ADDR) $(SERVE_ROOT)

# Deprecated: PHP 7.4 on Ubuntu 18.04. Prefer `make test` (22.04).
.PHONY: test-legacy-18
test-legacy-18: rebuild-changed
	docker run -v $(shell pwd):/compiler ircmaxell/php-compiler:18.04-dev php vendor/bin/phpunit

.PHONY: test-18
test-18: test-legacy-18

# Ubuntu 22.04 + PHP 8.2 dev image (issues #73, #202). Build once: make docker-build-22
PHP_COMPILER_DEV_IMAGE ?= ghcr.io/PurHur/php-compiler:dev
LOCAL_DEV_IMAGE ?= php-compiler:22.04-dev

.PHONY: docker-build-22 docker-publish-dev
docker-build-22:
	docker build -f Docker/dev/ubuntu-22.04/Dockerfile -t $(LOCAL_DEV_IMAGE) -t $(PHP_COMPILER_DEV_IMAGE) .

# Maintainer: push dev image to ghcr.io (issue #202; requires docker login ghcr.io)
docker-publish-dev:
	./script/docker-publish-dev.sh --push

# Run full local CI inside Docker (memory-capped; see script/ci-defaults.env)
.PHONY: test-docker test-docker-safe
test-docker: docker-build-22
	./script/ci-docker-safe.sh ci-local.sh

# Alias: explicit name for memory-capped Docker CI (issues #497, #501)
test-docker-safe: test-docker

test-docker-fast-safe: docker-build-22
	./script/ci-docker-safe.sh ci-fast.sh

# Runforge / harness CI: uses docker-ci-local.sh tar fallback when bind-mount is empty (#272).
# Optional: make test-harness ARGS='--filter VMTest'
.PHONY: test-harness test-docker-exec
test-harness:
	./script/docker-ci-local.sh $(ARGS)

# Ad-hoc commands in the dev image (tar fallback when bind-mount is incomplete).
test-docker-exec:
	./script/docker-exec.sh $(ARGS)

# Quick PHPUnit in 22.04 dev image (deprecated: prefer test-docker-fast / ci-fast.sh)
.PHONY: test-docker-quick
test-docker-quick: test-docker-fast

.PHONY: bootstrap-inventory bootstrap-profile bootstrap-aot-lint bootstrap-aot-link bootstrap-aot-link-lib bootstrap-selfhost-probe bootstrap-selfhost-link bootstrap-selfhost-compile-smoke bootstrap-selfhost-compiler-driver-smoke bootstrap-selfhost-lib-spine-smoke bootstrap-selfhost-lib-spine-vm-smoke bootstrap-selfhost-helloworld bootstrap-loop-gen1-link bootstrap-loop-probe bootstrap-loop-probe-dry bootstrap-wave-check
bootstrap-inventory:
	php script/bootstrap-inventory.php
bootstrap-profile: bootstrap-inventory
	php script/bootstrap-profile.php
bootstrap-aot-lint: bootstrap-profile
	php script/bootstrap-aot-lint.php
bootstrap-aot-link: bootstrap-profile
	./script/bootstrap-aot-link.sh
bootstrap-aot-link-lib: bootstrap-profile
	./script/bootstrap-aot-link-lib.sh
bootstrap-selfhost-probe:
	./script/bootstrap-selfhost-compile-probe.sh
bootstrap-selfhost-link:
	./script/bootstrap-selfhost-link.sh
bootstrap-selfhost-compile-smoke:
	./script/bootstrap-selfhost-compile-smoke-link.sh
bootstrap-selfhost-compile-smoke-run:
	./script/bootstrap-selfhost-compile-smoke-run.sh
bootstrap-selfhost-compiler-driver-smoke:
	./script/bootstrap-selfhost-compiler-driver-smoke-link.sh
bootstrap-selfhost-lib-spine-smoke:
	./script/bootstrap-selfhost-lib-spine-smoke-link.sh
bootstrap-selfhost-lib-spine-vm-smoke:
	./script/bootstrap-selfhost-lib-spine-vm-smoke.sh
bootstrap-selfhost-helloworld:
	./script/bootstrap-selfhost-helloworld-probe.sh
bootstrap-loop-gen1-link:
	BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1 ./script/bootstrap-loop-gen1-link.sh
bootstrap-loop-probe:
	./script/bootstrap-loop-probe.sh
bootstrap-loop-probe-dry:
	./script/bootstrap-loop-probe.sh --dry-run
bootstrap-wave-check:
	./script/bootstrap-wave-check.sh
