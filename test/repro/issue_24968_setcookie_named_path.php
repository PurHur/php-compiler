<?php
/** #24968 named path: without expires_or_options */
error_reporting(E_ALL);
ob_start();
var_export(setcookie(name: 'n', value: 'v', path: '/'));
echo "\n";
var_export(setrawcookie(name: 'n', value: 'v', path: '/'));
echo "\n";
$rf = new ReflectionFunction('setcookie');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), '=';
    echo $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '(none)';
    echo "\n";
}
