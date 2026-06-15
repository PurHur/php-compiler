--TEST--
Pipe + first-class callable assignment invokes like Zend PHP 8.5 (issue #8836, zend_compile.c)
--FILE--
<?php
$fn = "hi" |> strtoupper(...);
echo get_debug_type($fn), "\n";
echo $fn, "\n";
echo "hi" |> strtoupper(...);
echo "\n";
echo "hi" |> strtoupper(...) |> strlen(...);
--EXPECT--
string
HI
HI
2
