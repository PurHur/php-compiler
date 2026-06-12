--TEST--
Closure::getUsedVariables() — captured use() map (#6067, zend_closures.c)
--FILE--
<?php
$x = 1;
$y = 'two';
$c = function () use ($x, &$y) {
    return $x . $y;
};
var_export(method_exists($c, 'getUsedVariables'));
echo "\n";
$used = $c->getUsedVariables();
ksort($used);
var_export($used);
echo "\n";
$y = 'updated';
var_export($c->getUsedVariables()['y']);
echo "\n";
--EXPECT--
true
array (
  'x' => 1,
  'y' => 'two',
)
'updated'
