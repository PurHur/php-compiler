<?php
/**
 * Repro #24968 — named setcookie/setrawcookie with skipped expires_or_options must not crash.
 */
error_reporting(E_ALL);
ob_start();
try {
    var_export(setcookie(name: 'n', value: 'v', path: '/'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(setrawcookie(name: 'n2', value: 'v2', path: '/'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$r = new ReflectionFunction('setcookie');
foreach ($r->getParameters() as $p) {
    if (!$p->isOptional()) {
        continue;
    }
    echo $p->getName(), '=';
    var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : 'NONE');
    echo "\n";
}
