--TEST--
Language: array == / <=> ignore key order; === stays order-sensitive (#23985, #23988, Zend zend_hash_compare)
--FILE--
<?php
echo ([0 => 1, 1 => 2] == [1 => 2, 0 => 1]) ? "true\n" : "false\n";
echo (['a' => 1, 'b' => 2] == ['b' => 2, 'a' => 1]) ? "true\n" : "false\n";
echo ([0 => 1, 1 => 2] === [1 => 2, 0 => 1]) ? "true\n" : "false\n";
echo ([0 => 1, 1 => 2] <=> [1 => 2, 0 => 1]), "\n";
echo (['a' => 1, 'b' => 2] <=> ['b' => 2, 'a' => 1]), "\n";
echo ([0 => 1] <=> [0 => 1, 1 => 2]), "\n";
echo ([0 => 1, 1 => 2] <=> [0 => 1]), "\n";
echo ([0 => 1, 1 => 2] == [0 => 1, 1 => 3]) ? "true\n" : "false\n";
?>
--EXPECT--
true
true
false
0
0
-1
1
false
