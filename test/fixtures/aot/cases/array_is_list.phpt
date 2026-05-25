--TEST--
AOT: array_is_list()
--FILE--
<?php
echo array_is_list([]) ? "empty\n" : "bad\n";
echo array_is_list([1, 2, 3]) ? "packed\n" : "bad\n";
echo array_is_list(['a' => 1]) ? "bad\n" : "assoc\n";
--EXPECT--
empty
packed
assoc
