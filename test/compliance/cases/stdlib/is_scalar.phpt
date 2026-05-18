--TEST--
stdlib is_scalar() for supported types
--FILE--
<?php
echo is_scalar(0) ? 'y' : 'n', "\n";
echo is_scalar(1.5) ? 'y' : 'n', "\n";
echo is_scalar(true) ? 'y' : 'n', "\n";
echo is_scalar('hi') ? 'y' : 'n', "\n";
echo is_scalar(null) ? 'y' : 'n', "\n";
--EXPECT--
y
y
y
y
n
