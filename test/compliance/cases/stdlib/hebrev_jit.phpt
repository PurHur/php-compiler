--TEST--
stdlib hebrev() JIT (#3450)
--JIT--
--FILE--
<?php
$s = "אבג";
echo bin2hex(hebrev($s)), "\n";

$iso = "\xe0\xe1\xe2";
echo bin2hex(hebrev($iso)), "\n";

$mixed = 'hello '."\xe0\xe1\xe2".' world';
echo bin2hex(hebrev($mixed)), "\n";

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
