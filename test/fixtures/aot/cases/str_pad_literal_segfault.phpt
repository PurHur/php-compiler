--TEST--
AOT: str_pad() three-literal args must not segfault (#23911)
--FILE--
<?php
echo str_pad('p', 5, '-'), "\n";
$x = 5;
echo str_pad('p', $x, '-'), "\n";
$y = 3;
echo str_pad('p', $y + 2, '-'), "\n";
--EXPECT--
p----
p----
p----
