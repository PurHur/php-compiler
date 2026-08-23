<?php

class UserC
{
}

$ru = new ReflectionClass(UserC::class);
$ri = new ReflectionClass(stdClass::class);

$uf = $ru->getFileName();
echo is_string($uf) ? 'user-str' : var_export($uf, true), "\n";
echo basename((string) $uf), "\n";
var_dump($ri->getFileName());
