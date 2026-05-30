--TEST--
AOT strtok() continuation tokens (issue #3201)
--FILE--
<?php
$s = "a,b,c";
echo strtok($s, ",");
echo strtok(",");
echo strtok(",");
echo strtok(",") === false ? "end" : "bad";
--EXPECT--
abcend

