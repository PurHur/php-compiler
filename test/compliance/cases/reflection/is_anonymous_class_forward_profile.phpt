--TEST--
Reflection: isAnonymousClass() — PHP 8.4 forward profile (#19969, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

var_export(function_exists('isAnonymousClass'));
echo "\n";
var_export(isAnonymousClass(new class {}));
echo "\n";
var_export(isAnonymousClass(new stdClass()));
echo "\n";
$anon = new class extends stdClass {};
var_export(isAnonymousClass($anon));
echo "\n";
--EXPECT--
true
true
false
true
