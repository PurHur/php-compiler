--TEST--
stdlib forward-profile enums — registered on PHP_COMPILER_PROFILE=8.4 (#17793)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo enum_exists('RoundingMode') ? '1' : '0';
echo enum_exists('Random\IntervalBoundary') ? '1' : '0';
echo class_exists('EnumCases') ? '1' : '0';
echo "\n";
--EXPECT--
111
