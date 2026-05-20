--TEST--
stdlib array_map() with string callback
--FILE--
<?php
$rows = [1, 2, 3];
$out = array_map('strval', $rows);
echo $out[0], $out[1], $out[2], "\n";
--EXPECT--
123
