--TEST--
stdlib str_starts_with()
--FILE--
<?php
echo str_starts_with('hello', 'he') ? 'y' : 'n', "\n";
echo str_starts_with('hello', 'lo') ? 'y' : 'n', "\n";
echo str_starts_with('hi', 'hello') ? 'y' : 'n', "\n";
--EXPECT--
y
n
n
