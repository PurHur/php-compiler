--TEST--
AOT: nested nullsafe ?-> + ?? matches Zend (n|5) — no terminator mid-block (#26818)
--FILE--
<?php
$a = null;
echo $a?->x ?? "n";
echo "|";
$b = (object)["c" => (object)["d" => 5]];
echo $b?->c?->d;
echo "\n";
--EXPECT--
n|5
