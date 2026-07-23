--TEST--
getmxrr()/dns_get_mx() &$weight accepts non-array and rebinds (#22707, ext/standard/dns.c)
--FILE--
<?php
$hosts = [];
$w = false;
$r = @getmxrr('php.net', $hosts, $w);
echo ($r ? 'y' : 'n'), '|', gettype($w), '|', is_array($w) && count($w) === count($hosts) ? 'aligned' : 'bad', "\n";

$hosts2 = [];
$w2 = 0;
$r2 = @dns_get_mx('php.net', $hosts2, $w2);
echo ($r2 ? 'y' : 'n'), '|', gettype($w2), "\n";

$hosts3 = [];
$w3 = [];
$r3 = @getmxrr('php.net', $hosts3, $w3);
echo ($r3 ? 'y' : 'n'), '|', gettype($w3), "\n";
--EXPECT--
y|array|aligned
y|array
y|array
