--TEST--
AOT: str_repeat() named string:/times: arguments (#23204)
--FILE--
<?php
echo str_repeat(string: 'x', times: 3), "\n";
echo str_repeat(string: 'ab', times: 2), "\n";
--EXPECT--
xxx
abab
