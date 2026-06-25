--TEST--
stdlib print_r nested call with dual literal true — return string not empty (#11400, ext/standard/print.c)
--FILE--
<?php
declare(strict_types=1);

echo print_r(in_array('x', ['x'], true), true);
echo print_r(array_search('y', ['x', 'y'], true), true);
--EXPECT--
1
1
