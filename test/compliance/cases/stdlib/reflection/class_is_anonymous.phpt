--TEST--
stdlib ReflectionClass::isAnonymous() — anonymous vs named class (#5105, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

$anon = new ReflectionClass(new class {});
var_export($anon->isAnonymous());
echo "\n";

$named = new ReflectionClass(stdClass::class);
var_export($named->isAnonymous());
echo "\n";

$anonExtends = new ReflectionClass(new class extends stdClass {});
var_export($anonExtends->isAnonymous());
echo "\n";
--EXPECT--
true
false
true
