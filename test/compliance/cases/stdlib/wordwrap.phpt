--TEST--
stdlib wordwrap()
--FILE--
<?php
echo wordwrap('hello world', 5), "\n";
echo wordwrap('The quick brown fox', 10), "\n";
echo wordwrap('1234567890', 3, ':', true), "\n";
echo wordwrap('a test', 3, '|'), "\n";
echo wordwrap('line1' . "\n" . 'line2', 80), "\n";
echo wordwrap('', 5), "\n";
echo wordwrap('foo', 10), "\n";
echo wordwrap('verylongword', 5, "\n", true), "\n";
--EXPECT--
hello
world
The quick
brown fox
123:456:789:0
a|test
line1
line2

foo
veryl
ongwo
rd
