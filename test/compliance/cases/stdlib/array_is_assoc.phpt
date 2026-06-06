--TEST--
stdlib array_is_assoc()
--FILE--
<?php
echo array_is_assoc([]) ? "bad\n" : "empty\n";
echo array_is_assoc([1, 2, 3]) ? "bad\n" : "packed\n";
echo array_is_assoc(['a' => 1, 'b' => 2]) ? "assoc\n" : "bad\n";
echo array_is_assoc([0 => 'a', 1 => 'b']) ? "bad\n" : "list\n";
echo array_is_assoc([0 => 1, 2 => 2]) ? "hole\n" : "bad\n";
--EXPECT--
empty
packed
assoc
list
hole
