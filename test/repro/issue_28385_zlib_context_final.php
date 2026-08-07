<?php

declare(strict_types=1);

/**
 * Repro #28385 — InflateContext / DeflateContext must be final
 * (php-src ext/zlib/zlib.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28385_zlib_context_final.php
 */
inflate_init(ZLIB_ENCODING_RAW);
deflate_init(ZLIB_ENCODING_RAW);
foreach (['InflateContext', 'DeflateContext'] as $c) {
    echo $c, ' isFinal=', var_export((new ReflectionClass($c))->isFinal(), true), "\n";
}
eval('class BadInflate extends InflateContext {}');
echo "EXTENDED_OK\n";
