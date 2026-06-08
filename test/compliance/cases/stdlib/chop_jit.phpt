--TEST--
stdlib chop() JIT — rtrim() alias (#4965)
--JIT--
--FILE--
<?php
echo chop('  ab  '), "\n";
echo chop("xy\t\n"), "\n";
--EXPECT--
  ab
xy
