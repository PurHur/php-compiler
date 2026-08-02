--TEST--
AOT: hex2bin() empty, binary, and invalid input
--FILE--
<?php
echo strlen(hex2bin('')) === 0 ? 'empty' : 'bad', "\n";
echo bin2hex(hex2bin('0f0f')), "\n";
// Inline === false (avoid bool-local === which is a separate AOT gap; #27008).
echo (hex2bin('abc') === false) ? 'odd' : 'bad', "\n";
--EXPECT--
empty
0f0f
odd
