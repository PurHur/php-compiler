--TEST--
stdlib array_slice() JIT — numeric-string offset/length coercion (#4176)
--FILE--
<?php
echo json_encode(array_slice([1, 2, 3], '1', '1')), "\n";
--EXPECT--
[2]
