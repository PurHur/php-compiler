--TEST--
stdlib parse_str() returns null not true — void semantics (#10458, ext/standard/basic_functions.c)
--FILE--
<?php
$result = null;
$r = parse_str('a=1&b=2', $result);
echo null === $r ? 'null' : 'val', "\n";
echo $result['a'], ' ', $result['b'], "\n";
--EXPECT--
null
1 2
