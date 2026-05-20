--TEST--
language ternary operator (?:)
--FILE--
<?php
echo true ? 'yes' : 'no', "\n";
echo false ? 'yes' : 'no', "\n";
echo (0 ? 'zero' : 'nonzero'), "\n";
echo (1 ? 'one' : 'other'), "\n";
$x = 2;
echo ($x > 1 ? 'gt' : 'le'), "\n";
--EXPECT--
yes
no
nonzero
one
gt
