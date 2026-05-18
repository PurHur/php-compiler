--TEST--
Integration: strpos, str_contains, str_starts_with, str_ends_with, strncmp
--FILE--
<?php
echo strpos('foobar', 'bar'), "\n";
echo str_contains('foobar', 'oba') ? 'y' : 'n', "\n";
echo str_starts_with('foobar', 'foo') ? 'y' : 'n', "\n";
echo str_ends_with('foobar', 'bar') ? 'y' : 'n', "\n";
echo strncmp('foo', 'fop', 2), "\n";
--EXPECT--
3
y
y
y
0
