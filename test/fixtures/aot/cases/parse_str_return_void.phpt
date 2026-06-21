--TEST--
AOT: parse_str() returns null not true — void semantics (#10458)
--FILE--
<?php
$result = [];
$r = parse_str('x=9&y=10', $result);
echo null === $r ? 'null' : 'val', "\n";
echo $result['x'], ':', $result['y'], "\n";
--EXPECT--
null
9:10
