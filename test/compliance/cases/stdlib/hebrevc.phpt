--TEST--
stdlib hebrevc() visual Hebrew with newline conversion (#17183)
--FILE--
<?php
$shalomOlam = "\xf9\xec\xe5\xed\x20\xf2\xe5\xec\xed";
echo bin2hex(hebrevc($shalomOlam, 5)), "\n";
echo function_exists('hebrevc') ? "yes\n" : "no\n";
echo '' === hebrevc('') ? "empty\n" : "not-empty\n";
--EXPECT--
ede5ecf9200aedece5f2
yes
empty
