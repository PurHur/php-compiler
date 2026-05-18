--TEST--
stdlib ltrim()
--FILE--
<?php
echo ltrim('  ab  '), "\n";
echo ltrim("\t\nxy"), "\n";
--EXPECT--
ab  
xy
