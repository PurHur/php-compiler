--TEST--
Language: array === requires identical element types (#23485, Zend is_identical_function / zend_hash_compare)
--FILE--
<?php
echo ([1, 2] === [1, '2']) ? "true\n" : "false\n";
echo ([1.0] === [1]) ? "true\n" : "false\n";
echo ([[1]] === [['1']]) ? "true\n" : "false\n";
echo ([1, 2] == [1, '2']) ? "true\n" : "false\n";
echo ([1, 2] === [1, 2]) ? "true\n" : "false\n";
echo ([1, 2] !== [1, '2']) ? "true\n" : "false\n";
echo (['a' => 1] === ['a' => '1']) ? "true\n" : "false\n";
echo (['a' => 1] === ['a' => 1]) ? "true\n" : "false\n";
?>
--EXPECT--
false
false
false
true
true
true
false
true
