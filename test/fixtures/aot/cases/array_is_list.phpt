--TEST--
AOT: array_is_list()
--FILE--
<?php
echo array_is_list([]) ? "empty\n" : "bad\n";
echo array_is_list([1, 2, 3]) ? "packed\n" : "bad\n";
echo array_is_list(['a' => 1]) ? "bad\n" : "assoc\n";
echo array_is_list([0 => 1, 2 => 2]) ? "bad\n" : "hole\n";
echo array_is_list([1 => 'x']) ? "bad\n" : "non_zero_start\n";
echo array_is_list(['0' => 'x', 1 => 'y']) ? "numeric_string_key\n" : "bad\n";
--EXPECT--
empty
packed
assoc
hole
non_zero_start
numeric_string_key
