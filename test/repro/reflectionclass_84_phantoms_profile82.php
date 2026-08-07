<?php
/**
 * Issue #22599 / #28518 — ReflectionClass PHP 8.4 APIs must be absent on PROFILE=8.2 / reference.
 * php-src: ext/reflection/php_reflection.stub.php (isStatic never on ReflectionClass).
 *
 * Run: PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/reflectionclass_84_phantoms_profile82.php
 */
foreach ([
    'getDeprecatedMessage',
    'getDeprecatedVersion',
    'getLazyPropertyNames',
    'getReadOnlyProperties',
    'isStatic',
] as $method) {
    echo $method, '=', method_exists(ReflectionClass::class, $method) ? '1' : '0', "\n";
}
try {
    (new ReflectionClass(DateTime::class))->getDeprecatedMessage();
    echo "call=ok\n";
} catch (Error $e) {
    echo 'call=', str_contains($e->getMessage(), 'getDeprecatedMessage') ? 'undefined' : $e->getMessage(), "\n";
}
