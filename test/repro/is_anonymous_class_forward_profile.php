<?php
declare(strict_types=1);

// Historical forward-profile probe (#19969) — free function retired as phantom (#28616).
var_export(function_exists('isAnonymousClass'));
echo "\n";
$anon = new class {};
var_export((new ReflectionClass($anon))->isAnonymous());
echo "\n";
var_export((new ReflectionClass(stdClass::class))->isAnonymous());
echo "\n";
