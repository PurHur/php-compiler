--TEST--
AOT: get_declared_enums() lists compiled enums (issue #3538)
--FILE--
<?php
enum Color: string { case Red = 'r'; case Blue = 'b'; }
enum Size: int { case S = 1; }
$enums = get_declared_enums();
echo count($enums) >= 2 ? '1' : '0';
echo in_array('Color', $enums, true) ? '1' : '0';
echo in_array('Size', $enums, true) ? '1' : '0';
echo "\n";
--EXPECT--
111
