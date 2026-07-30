<?php
declare(strict_types=1);
foreach (['strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
var_export(strpos('abc', 'z'));
echo "\n";
var_export(strstr('abc', 'z'));
echo "\n";
