--TEST--
Language: match default arm may precede other arms — JIT (#5359)
--FILE--
<?php
echo match (1) {
    default => 'd',
    1 => 'a',
}, "\n";
echo match (0) {
    default => 'd',
    0 => 'z',
}, "\n";
--EXPECT--
a
z
