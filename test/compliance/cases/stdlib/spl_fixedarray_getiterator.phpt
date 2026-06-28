--TEST--
SplFixedArray getIterator foreach (ext/spl/spl_fixedarray.c; #13077)
--FILE--
<?php
$a = SplFixedArray::fromArray([1, 2]);
$out = '';
foreach ($a as $k => $v) {
    $out .= $k . '=' . $v . ' ';
}
echo $out, "\n";
var_export(iterator_to_array($a->getIterator()));
?>
--EXPECT--
0=1 1=2 
array (
  0 => 1,
  1 => 2,
)
