<?php

declare(strict_types=1);

/**
 * #28528 — ReflectionParameter::{isSensitive,isSensitiveParameter} are phantoms vs php-src.
 * php-src stub (ext/reflection/php_reflection.stub.php) has neither method on 8.2–8.5.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28528_reflectionparameter_issensitive_phantoms_84.php
 */
$phantoms = ['isSensitive', 'isSensitiveParameter'];
$bad = [];
foreach ($phantoms as $m) {
    if (method_exists(ReflectionParameter::class, $m)) {
        $bad[] = $m;
    }
}
if ($bad !== []) {
    echo 'phantoms:', implode(',', $bad), "\n";
    exit(1);
}
echo "ok\n";
