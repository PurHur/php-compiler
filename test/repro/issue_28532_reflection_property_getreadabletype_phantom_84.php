<?php

declare(strict_types=1);

/**
 * #28532 — ReflectionProperty::getReadableType is a phantom vs php-src.
 * Zend ships getSettableType() only (ext/reflection/php_reflection.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28532_reflection_property_getreadabletype_phantom_84.php
 */
class C
{
    public string $a = 'a';
}

$p = new ReflectionProperty(C::class, 'a');
$readable = (int) method_exists($p, 'getReadableType');
$settable = (int) method_exists($p, 'getSettableType');
echo "getReadableType={$readable}\n";
echo "getSettableType={$settable}\n";
if (1 === $readable) {
    fwrite(STDERR, "phantom getReadableType still registered\n");
    exit(1);
}
echo "ok\n";
