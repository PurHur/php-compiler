--TEST--
stdlib str_ends_with()
--FILE--
<?php
echo str_ends_with('hello', 'lo') ? 'y' : 'n', "\n";
echo str_ends_with('hello', 'he') ? 'y' : 'n', "\n";
echo str_ends_with('hi', 'hello') ? 'y' : 'n', "\n";
echo str_ends_with('x', '') ? 'y' : 'n', "\n";
--EXPECT--
y
n
n
y
