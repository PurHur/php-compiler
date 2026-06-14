--TEST--
stdlib array_chunk() JIT — numeric-string and float length coercion (#4191)
--FILE--
<?php
echo json_encode(array_chunk([1, 2, 3, 4, 5], '2')), "\n";
echo json_encode(array_chunk([1, 2, 3], 2.9)), "\n";
--EXPECT--
[[1,2],[3,4],[5]]
[[1,2],[3]]
