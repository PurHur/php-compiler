--TEST--
Language: ??= dim write auto-vivifies undefined/null container (#21992, zend_execute.c)
--FILE--
<?php
$b['k'] ??= 'y';
echo $b['k'], "\n";

$c = null;
$c['k'] ??= 'y';
var_export($c);
echo "\n";

// Plain dim assign peers (same FETCH_DIM_W promotion).
$d['k'] = 'z';
echo $d['k'], "\n";
$e = null;
$e['k'] = 'z';
var_export($e);
echo "\n";

// True scalars still Error (peer #4878 / #6325).
try {
    $i = 0;
    $i['k'] ??= 'no';
    echo "scalar-ok\n";
} catch (Error $err) {
    echo get_class($err), ': ', $err->getMessage(), "\n";
}
--EXPECT--
y
array (
  'k' => 'y',
)
z
array (
  'k' => 'z',
)
Error: Cannot use a scalar value as an array
