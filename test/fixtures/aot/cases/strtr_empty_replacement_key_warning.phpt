--TEST--
AOT: strtr() empty replace_pairs key emits E_WARNING (#26704, ext/standard/string.c)
--FILE--
<?php
error_reporting(E_ALL);
$out = strtr('ab', ['' => 'x', 'a' => 'A']);
echo 'out=' . $out . "\n";
?>
--EXPECT--
PHP Warning:  strtr(): Ignoring replacement of empty string
out=Ab
