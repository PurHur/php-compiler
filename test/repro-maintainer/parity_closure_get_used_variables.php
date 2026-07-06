<?php
$x = 1;
$y = 'two';
$c = function () use ($x, &$y) {
    return $x . $y;
};
var_export(method_exists($c, 'getUsedVariables'));
echo "\n";
if (!method_exists($c, 'getUsedVariables')) {
    exit(0);
}
$used = $c->getUsedVariables();
ksort($used);
var_export($used);
echo "\n";
