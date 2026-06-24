--TEST--
SPL ArrayIterator::append() pushes next numeric index (#11328, ext/spl/spl_array.c)
--FILE--
<?php
$ai = new ArrayIterator(['a' => 1]);
$ai->append(2);
echo json_encode(iterator_to_array($ai)), "\n";
?>
--EXPECT--
{"a":1,"0":2}
