<?php
$a = [1, 2, 3];
$it = new ArrayIterator($a);
foreach ($it as &$v) {
    $v *= 10;
}
unset($v);
echo 'src=';
var_export($a);
echo "\n";
echo 'it=';
var_export(iterator_to_array($it));
echo "\n";
