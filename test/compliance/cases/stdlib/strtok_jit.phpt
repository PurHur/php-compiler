--TEST--
JIT: strtok() (#3201)
--FILE--
<?php
$s = "x-y-z";
echo strtok($s, "-");
echo strtok("-");
echo strtok("-");
echo strtok("-") === false ? "done" : "bad";
--EXPECT--
xyzdone

