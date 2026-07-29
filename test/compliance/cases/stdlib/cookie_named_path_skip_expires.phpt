--TEST--
setcookie setrawcookie named path skips expires_or_options (#24968, ext/standard/head.c)
--FILE--
<?php
error_reporting(E_ALL);
ob_start();
var_export(setcookie(name: 'n', value: 'v', path: '/'));
echo "\n";
var_export(setrawcookie(name: 'n2', value: 'v2', path: '/'));
echo "\n";
var_export(setcookie('n3', 'v3', 0, '/'));
echo "\n";
$r = new ReflectionFunction('setcookie');
foreach ($r->getParameters() as $p) {
    if (!$p->isOptional()) {
        continue;
    }
    echo $p->getName(), '=';
    var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : 'NONE');
    echo "\n";
}
--EXPECT--
true
true
true
value=''
expires_or_options=0
path=''
domain=''
secure=false
httponly=false
