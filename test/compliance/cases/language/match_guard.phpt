--TEST--
match guard arms — expression patterns evaluated before === compare (#3397)
--FILE--
<?php
$x = 3;
echo match (true) {
    $x > 5 => 'big',
    $x > 0 => 'pos',
    default => 'other',
}, "\n";
echo match (true) {
    match (true) { $x > 2 => true, default => false } => 'nested',
    default => 'fail',
}, "\n";
--EXPECT--
pos
nested
