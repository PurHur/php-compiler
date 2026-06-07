--TEST--
stdlib class_constants() — interface and enum constant maps (JIT, issue #7309)
--FILE--
<?php
interface I7309 { const X = 1; }
enum E7309: string { case A = 'a'; case B = 'b'; }
var_export(class_constants('I7309'));
echo "\n";
var_export(class_constants(E7309::class));
echo "\n";
--EXPECT--
array (
  'X' => 1,
)
array (
  'A' => \E7309::A,
  'B' => \E7309::B,
)
