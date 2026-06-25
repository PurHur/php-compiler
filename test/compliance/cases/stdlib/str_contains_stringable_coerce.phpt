--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() — __toString coercion without strict_types (#11398, ext/standard/string.c)
--FILE--
<?php

class StringableObj {
    public function __toString(): string { return 'hello world'; }
}

$obj = new StringableObj();
var_export(str_contains($obj, 'lo'));
echo "\n";
var_export(str_starts_with($obj, 'hel'));
echo "\n";
var_export(str_ends_with($obj, 'rld'));
echo "\n";
--EXPECT--
true
true
true
