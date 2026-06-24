--TEST--
stdlib array_map() closure + inline array with object element (#11304, ext/standard/array.c)
--FILE--
<?php
$r = array_map(fn($x) => $x, [new stdClass()]);
echo is_object($r[0]) ? "object\n" : "not_object\n";
$o = new stdClass();
$s = array_map(fn($x) => $x, [$o]);
echo ($s[0] === $o) ? "same\n" : "diff\n";
--EXPECT--
object
same
