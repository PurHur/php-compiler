--TEST--
stdlib is_object()
--FILE--
<?php
echo is_object('x') ? 'y' : 'n', "\n";
echo is_object(1) ? 'y' : 'n', "\n";
echo is_object(null) ? 'y' : 'n', "\n";
--EXPECT--
n
n
n
