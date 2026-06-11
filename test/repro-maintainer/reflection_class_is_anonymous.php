<?php
declare(strict_types=1);

$anon = new ReflectionClass(new class {});
var_export($anon->isAnonymous());
echo "\n";

$named = new ReflectionClass(stdClass::class);
var_export($named->isAnonymous());
echo "\n";
