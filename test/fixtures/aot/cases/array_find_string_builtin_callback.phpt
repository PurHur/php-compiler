--TEST--
AOT: array_find family string builtin callback arity (#17300, #13946)
--FILE--
<?php
echo array_all([1, 2, 3], 'is_int') ? "T\n" : "F\n";
echo array_all_key(['a' => 1], 'is_string') ? "T\n" : "F\n";
--EXPECT--
T
T
