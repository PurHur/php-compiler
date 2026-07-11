--TEST--
SplFixedArray jsonSerialize / json_encode (ext/spl/spl_fixedarray.c; #13087)
--FILE--
<?php
$fa = SplFixedArray::fromArray([1, 2, 3]);
echo json_encode($fa->jsonSerialize()), "\n";
echo json_encode($fa), "\n";
$sparse = new SplFixedArray(2);
$sparse[1] = 2;
echo json_encode($sparse), "\n";
?>
--EXPECT--
[1,2,3]
[1,2,3]
[null,2]
