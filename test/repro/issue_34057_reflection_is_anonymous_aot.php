<?php

class Named {}

$anon = new class {};
$anonName = $anon::class;

echo 'N=', (new ReflectionClass(Named::class))->isAnonymous() ? '1' : '0', "\n";
echo 'A=', (new ReflectionClass($anonName))->isAnonymous() ? '1' : '0', "\n";
echo 'E=', (new ReflectionClass(Exception::class))->isAnonymous() ? '1' : '0', "\n";
