--TEST--
SplFixedArray getSize/setSize resize API (ext/spl/spl_fixedarray.c; #13086)
--FILE--
<?php
$fa = new SplFixedArray(2);
$fa[0] = 10;
$fa[1] = 20;
$fa->setSize(4);
echo $fa->getSize(), "\n";
echo count($fa), "\n";
echo var_export($fa[2], true), "\n";
$fa->setSize(2);
echo var_export($fa->toArray(), true), "\n";
?>
--EXPECT--
4
4
NULL
array (
  0 => 10,
  1 => 20,
)
