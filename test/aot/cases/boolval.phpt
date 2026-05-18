--TEST--
AOT: boolval()
--FILE--
<?php
echo boolval(0) ? 'y' : 'n', "\n";
echo boolval(1) ? 'y' : 'n', "\n";
echo boolval('') ? 'y' : 'n', "\n";
echo boolval('x') ? 'y' : 'n', "\n";
--EXPECT--
n
y
n
y
