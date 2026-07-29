--TEST--
stdlib setcookie/setrawcookie named path skips expires_or_options (#24968)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
error_reporting(E_ALL);
ob_start();
var_export(setcookie(name: 'n', value: 'v', path: '/'));
echo "\n";
var_export(setrawcookie(name: 'nr', value: 'vr', path: '/app'));
echo "\n";
var_export(setcookie('n2', 'v2', 0, '/'));
echo "\n";
$rf = new ReflectionFunction('setcookie');
foreach ($rf->getParameters() as $p) {
    if (in_array($p->getName(), ['value', 'path', 'domain'], true)) {
        echo $p->getName(), '=';
        var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : null);
        echo "\n";
    }
}
--EXPECT--
true
true
true
value=''
path=''
domain=''
