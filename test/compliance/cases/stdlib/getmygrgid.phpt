--TEST--
stdlib getmygrgid() returns real group id (issue #3611)
--FILE--
<?php
$g = getmygrgid();
echo $g >= 0 ? "gid\n" : "bad\n";
echo getmygrgid() === $g ? "stable\n" : "bad\n";
--EXPECT--
gid
stable
