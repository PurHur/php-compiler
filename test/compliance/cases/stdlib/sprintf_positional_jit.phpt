--TEST--
stdlib sprintf() positional arguments JIT/AOT — issue #3631
--FILE--
<?php
function positional_sprintf(): void {
    echo sprintf('%2$s %1$s', 'world', 'hello'), "\n";
    echo sprintf('%1$d-%2$d', 10, 20), "\n";
    echo sprintf('%2$.2f %1$s', 'pi', 3.14159), "\n";
}
positional_sprintf();
--EXPECT--
hello world
10-20
3.14 pi
