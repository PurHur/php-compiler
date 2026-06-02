--TEST--
AOT: array_combine() empty keys or values return false (#4353)
--FILE--
<?php
echo array_combine([], []) === false ? "false\n" : "not-false\n";
echo array_combine(['a'], []) === false ? "false\n" : "not-false\n";
echo array_combine([], ['x']) === false ? "false\n" : "not-false\n";
--EXPECT--
false
false
false
