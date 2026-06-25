--TEST--
AOT: in_array() / array_search() on packed list (#11553)
--FILE--
<?php
$list = [1, 2, 3];
echo in_array(2, $list) ? "in_y\n" : "in_n\n";
echo in_array(2, [1, 2, 3]) ? "inline_y\n" : "inline_n\n";
echo array_search(20, [10, 20, 30]), "\n";
--EXPECT--
in_y
inline_y
1
