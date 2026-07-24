<?php
declare(strict_types=1);

// Repro #22899 — default profile must match Zend 8.2 (isSensitive absent);
// PHP_COMPILER_PROFILE=8.4 advertises and probes #[\SensitiveParameter].
foreach (['isSensitive', 'isSensitiveParameter'] as $m) {
    echo $m, '=', method_exists(ReflectionParameter::class, $m) ? 'y' : 'n', "\n";
}

if (method_exists(ReflectionParameter::class, 'isSensitive')) {
    function f_issensitive_repro(#[\SensitiveParameter] string $p) {}
    function g_issensitive_repro(string $p) {}
    $rf = new ReflectionParameter('f_issensitive_repro', 0);
    $rg = new ReflectionParameter('g_issensitive_repro', 0);
    echo 'probe=', $rf->isSensitive() ? 'y' : 'n', '/', $rg->isSensitive() ? 'y' : 'n', "\n";
}
