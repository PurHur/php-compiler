--TEST--
AOT: strtok literal init+continue (#26906)
--FILE--
<?php
echo strtok('a.b.c', '.');
echo ',';
echo strtok('.');
echo "\n";
--EXPECT--
a,b
