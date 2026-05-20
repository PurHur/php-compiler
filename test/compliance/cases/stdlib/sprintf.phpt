--TEST--
stdlib sprintf()
--FILE--
<?php
echo sprintf('x=%s', 'hi'), "\n";
echo sprintf('n=%d', 42), "\n";
echo sprintf('f=%f', 1.5), "\n";
echo sprintf('pct=%%'), "\n";
echo sprintf('%s %d', 'a', 7), "\n";
--EXPECT--
x=hi
n=42
f=1.500000
pct=%
a 7
