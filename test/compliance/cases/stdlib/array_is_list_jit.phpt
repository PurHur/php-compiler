--TEST--
stdlib array_is_list() JIT
--FILE--
<?php
echo array_is_list([]) ? "empty\n" : "bad\n";
echo array_is_list([1, 2, 3]) ? "packed\n" : "bad\n";
echo array_is_list(['a' => 1]) ? "bad\n" : "assoc\n";
echo array_is_list([0 => 1, 2 => 2]) ? "bad\n" : "hole\n";
--EXPECT--
empty
packed
assoc
hole
