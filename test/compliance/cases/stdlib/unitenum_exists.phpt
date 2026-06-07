--TEST--
Stdlib: unitenum_exists() pure vs backed enums (VM, #6884)
--FILE--
<?php
enum Pure { case A; }
enum Backed: string { case A = 'x'; }

echo unitenum_exists('Pure') ? '1' : '0';
echo unitenum_exists('Backed') ? '1' : '0';
echo unitenum_exists('Missing') ? '1' : '0';
echo enum_exists('Pure') ? '1' : '0';
echo enum_exists('Backed') ? '1' : '0';
echo function_exists('unitenum_exists') ? '1' : '0';
echo "\n";
--EXPECT--
100111
