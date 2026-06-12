--TEST--
stdlib sprintf() positional arguments (%n$) — issue #3631
--FILE--
<?php
echo sprintf('%2$s %1$s', 'world', 'hello'), "\n";
echo sprintf('%1$d-%2$d', 10, 20), "\n";
echo sprintf('%2$.2f %1$s', 'pi', 3.14159), "\n";
--EXPECT--
hello world
10-20
3.14 pi
