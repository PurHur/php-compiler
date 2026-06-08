--TEST--
AOT: pow()/sqrt()/log() numeric-string coercion (#4359)
--FILE--
<?php
echo pow('2', '3'), "\n";
echo sqrt('4'), "\n";
echo log('10'), "\n";
--EXPECT--
8
2
2.302585092994
