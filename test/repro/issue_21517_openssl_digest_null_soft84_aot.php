<?php

/**
 * AOT guard #21517 — openssl_digest(null) soft-null under PROFILE=8.4
 * (set_error_handler + null digest currently segfaults AOT compile; DEP checked on VM/JIT).
 *
 * PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=1 php bin/compile.php -o /tmp/i21517o \
 *   test/repro/issue_21517_openssl_digest_null_soft84_aot.php && /tmp/i21517o
 */

$empty = openssl_digest('', 'sha256');
$null = openssl_digest(null, 'sha256');
if (is_string($null) && $empty === $null) {
    echo "openssl_digest OK\n";
    exit(0);
}
echo "FAIL\n";
exit(1);
