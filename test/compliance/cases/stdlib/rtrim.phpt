--TEST--
stdlib rtrim()
--FILE--
<?php
echo rtrim('  ab  '), "\n";
echo rtrim("xy\t\n"), "\n";
--EXPECT--
  ab
xy
