--TEST--
AOT: fnmatch() named pattern:/filename: arguments (#23461)
--FILE--
<?php
echo fnmatch(pattern: 'a*', filename: 'abc') ? "true\n" : "false\n";
echo fnmatch(pattern: 'b*', filename: 'abc') ? "true\n" : "false\n";
--EXPECT--
true
false
