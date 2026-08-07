<?php

declare(strict_types=1);

/**
 * Repro #28384 — HashContext must be final
 * (php-src ext/hash/hash.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28384_hashcontext_final.php
 */
hash_init('sha256');
echo 'isFinal=', var_export((new ReflectionClass(HashContext::class))->isFinal(), true), "\n";
eval('class BadHashContext extends HashContext {}');
echo "EXTENDED_OK\n";
