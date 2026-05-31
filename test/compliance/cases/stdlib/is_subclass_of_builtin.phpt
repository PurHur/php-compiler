--TEST--
stdlib is_subclass_of() built-in Throwable hierarchy (issue #3563)
--FILE--
<?php
echo is_subclass_of('Exception', 'Throwable') ? '1' : '0';
echo is_subclass_of('Error', 'Throwable') ? '1' : '0';
echo is_a('Exception', 'Throwable', true) ? '1' : '0';
echo is_a('Error', 'Throwable', true) ? '1' : '0';
echo is_subclass_of('Throwable', 'Throwable') ? '1' : '0';
echo "\n";
--EXPECT--
11110
