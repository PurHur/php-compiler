--TEST--
SPL ArrayObject::append() pushes next numeric index (#11329, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject([1, 2]);
$ao->append(3);
echo json_encode(iterator_to_array($ao)), "\n";
$empty = new ArrayObject();
$empty->append('x');
echo json_encode(iterator_to_array($empty)), "\n";
?>
--EXPECT--
[1,2,3]
["x"]
