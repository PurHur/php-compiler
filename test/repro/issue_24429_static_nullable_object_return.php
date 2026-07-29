<?php

declare(strict_types=1);

/**
 * #24429 — under PHP_COMPILER_SPINE_CHUNK=1 + PHP_COMPILER_LLVM_ASSERT=1,
 * compiling ext/sockets/SocketsLibcThinAbi.php must not:
 *   1) structGep a non-pointer in VmStringCompare (VALUE-box with compileTimeString
 *      mistaken for native __string__*), or
 *   2) store `__value__*` into alloca `__value__` when coercing a static `?\FFI`
 *      property return to `__object__*` (module verify fail).
 *
 * Compile-only gate (load the class; do not invoke FFI):
 *
 *   PHP_COMPILER_LLVM_ASSERT=1 PHP_COMPILER_SPINE_CHUNK=1 \
 *     php bin/compile.php -o /tmp/x.bin test/repro/issue_24429_static_nullable_object_return.php
 */
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../ext/sockets/SocketsLibcThinAbi.php';
