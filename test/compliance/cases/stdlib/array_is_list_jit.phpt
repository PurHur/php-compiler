--TEST--
stdlib array_is_list() JIT
--FILE--
<?php
echo array_is_list([]) ? "empty\n" : "bad\n";
echo array_is_list([1, 2, 3]) ? "packed\n" : "bad\n";
echo array_is_list(['a' => 1]) ? "bad\n" : "assoc\n";
echo array_is_list([0 => 1, 2 => 2]) ? "bad\n" : "hole\n";
enum E: int { case A = 1; case B = 2; }
echo array_is_list([E::A, E::B]) ? "enum_list\n" : "bad\n";
--EXPECT--
empty
packed
assoc
hole
enum_list
