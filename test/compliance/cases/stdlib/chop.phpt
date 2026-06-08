--TEST--
stdlib chop() — rtrim() alias (ext/standard/string.c, #4965)
--FILE--
<?php
echo chop('  ab  '), "\n";
echo chop("xy\t\n"), "\n";
echo chop('left  ', ' '), "\n";
--EXPECT--
  ab
xy
left
