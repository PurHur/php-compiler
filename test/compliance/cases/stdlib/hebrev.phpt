--TEST--
stdlib hebrev() (#3450)
--FILE--
<?php
// UTF-8 Hebrew passes through unchanged (Zend behavior on PHP 8.2+).
$s = "אבג";
echo bin2hex(hebrev($s)), "\n";

// ISO-8859-8 aleph bet gimel reverses to visual order.
$iso = "\xe0\xe1\xe2";
echo bin2hex(hebrev($iso)), "\n";

// Mixed ASCII + ISO-8859-8 Hebrew block.
$mixed = 'hello '."\xe0\xe1\xe2".' world';
echo bin2hex(hebrev($mixed)), "\n";

// max_chars_per_line inserts newline (0x0a) between wrapped segments.
$shalomOlam = "\xf9\xec\xe5\xed\x20\xf2\xe5\xec\xed";
echo bin2hex(hebrev($shalomOlam, 5)), "\n";

echo function_exists('hebrev') ? "yes\n" : "no\n";
echo '' === hebrev('') ? "empty\n" : "not-empty\n";
--EXPECT--
d790d791d792
e2e1e0
776f726c6420e2e1e02068656c6c6f
ede5ecf90aedece5f2
yes
empty
