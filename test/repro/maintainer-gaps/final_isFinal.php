<?php
/**
 * Issue #27122 (re-#26339) — ReflectionProperty::isFinal() for plain final props.
 *
 * php-src-strict (PROFILE=8.4): Zend/zend_compile.c ZEND_ACC_FINAL +
 * ext/reflection/php_reflection.c zim_ReflectionProperty_isFinal.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer-gaps/final_isFinal.php
 *
 * Expect: true
 */
class C
{
    public final string $name = 'x';
}
var_export((new ReflectionProperty(C::class, 'name'))->isFinal());
echo "\n";
