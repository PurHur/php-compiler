<?php
/**
 * Issue #22601 — ReflectionProperty phantom methods vs Zend (re-#6451/#11442).
 * php-src: ext/reflection/php_reflection.stub.php
 *
 * Run: PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/reflectionproperty_phantoms.php
 * Compare: php test/repro/reflectionproperty_phantoms.php
 */
class RpPhantomC {
    public int $x = 1;
}

foreach ([
    'getRawValue',
    'setRawValue',
    'getMangledName',
    'isDefaultValueAvailable',
    'hasDefaultValue',
] as $method) {
    echo $method, '=', method_exists(ReflectionProperty::class, $method) ? '1' : '0', "\n";
}
try {
    (new ReflectionProperty(RpPhantomC::class, 'x'))->getMangledName();
    echo "call=ok\n";
} catch (Error $e) {
    echo 'call=', str_contains($e->getMessage(), 'getMangledName') ? 'undefined' : $e->getMessage(), "\n";
}
