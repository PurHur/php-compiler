<?php

declare(strict_types=1);

/**
 * Repro #28135 — GMP must be final under PHP_COMPILER_PROFILE≥8.4
 * (php-src ext/gmp/gmp.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28135_gmp_final.php
 */
echo 'isFinal=', var_export((new ReflectionClass(GMP::class))->isFinal(), true), "\n";
eval('class BadGmp extends GMP {}');
echo "EXTENDED_OK\n";
