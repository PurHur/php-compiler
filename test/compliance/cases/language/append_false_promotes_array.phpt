--TEST--
Language: []= on false auto-vivifies like null; true still Error (#22650, zend_execute.c)
--FILE--
<?php
$f = false;
$f[] = 1;
var_export($f);
echo "\n", gettype($f), "\n";

$g = false;
$g['k'] = 2;
var_export($g);
echo "\n";

$n = null;
$n[] = 3;
var_export($n);
echo "\n";

try {
    $t = true;
    $t[] = 1;
    echo "true-ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $i = 0;
    $i[] = 1;
    echo "int-ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
array (
  0 => 1,
)
array
array (
  'k' => 2,
)
array (
  0 => 3,
)
Error: Cannot use a scalar value as an array
Error: Cannot use a scalar value as an array
