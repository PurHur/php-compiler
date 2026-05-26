--TEST--
match selects literal arms (string and int) with default present (#2398, #2428)
--FILE--
<?php
echo match ('x') {
    'a' => 'A',
    'b' => 'B',
    'x' => 'X',
    default => 'Z',
}, "\n";
echo match (42) {
    41 => 'low',
    42 => 'hit',
    43 => 'high',
    default => 'none',
}, "\n";
--EXPECT--
X
hit
