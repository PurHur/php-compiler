--TEST--
stdlib array_pad() float length coercion without strict_types (#13876, ext/standard/array.c)
--FILE--
<?php
echo json_encode(array_pad([1, 2], 2.9, 0)), "\n";
--EXPECT--
[1,2]
