--TEST--
AOT: empty array loose == false (#3657)
--FILE--
<?php
echo ([] == false) ? "true\n" : "false\n";
echo ([] === false) ? "true\n" : "false\n";
echo ([1] == false) ? "true\n" : "false\n";
--EXPECT--
true
false
false
