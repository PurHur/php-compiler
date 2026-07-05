--TEST--
stdlib get_resource_id() on closed stream — stable id (#5133, ext/standard/basic_functions.c)
--FILE--
<?php
$f = fopen('php://memory', 'r');
$id = get_resource_id($f);
fclose($f);
echo get_resource_id($f) === $id ? "same\n" : "changed\n";
echo get_resource_type($f), "\n";
--EXPECT--
same
Unknown
