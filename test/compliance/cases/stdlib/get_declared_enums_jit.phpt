--TEST--
Stdlib: get_declared_enums() lists user enums only (JIT, #3538)
--FILE--
<?php
enum DeclaredColor: string { case Red = 'r'; case Blue = 'b'; }
enum DeclaredSize: int { case S = 1; }
interface DeclaredIfaceA {}
class DeclaredClassC {}
$enums = get_declared_enums();
echo count($enums) >= 2 ? '1' : '0';
echo in_array('DeclaredColor', $enums, true) ? '1' : '0';
echo in_array('DeclaredSize', $enums, true) ? '1' : '0';
echo in_array('DeclaredClassC', $enums, true) ? '1' : '0';
echo in_array('DeclaredIfaceA', $enums, true) ? '1' : '0';
echo "\n";
--EXPECT--
11100
