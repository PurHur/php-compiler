<?php
/**
 * AOT probe for #21581 — soft-null msgid → ''.
 *
 * Status 2026-07-21: AOT __compiler_gettext bridge segfaults on result use even for
 * gettext('hello') (pre-existing; unrelated to soft-null). VM/JIT cover #21581.
 * Re-enable when StringGettextRuntime AOT string-return bridge is fixed.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=1 ./script/docker-exec.sh -- bash -lc \
 *   'php bin/compile.php -o /tmp/i21581 test/repro/issue_21581_gettext_null_soft84_aot.php && /tmp/i21581'
 */
error_reporting(0);
echo 'gettext=', var_export(gettext(null), true), "\n";
echo 'ok=', gettext('hello'), "\n";
