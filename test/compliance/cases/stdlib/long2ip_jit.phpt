--TEST--
stdlib long2ip() / ip2long() JIT (issue #3225)
--FILE--
<?php
$a = long2ip(2130706433);
$b = ip2long('127.0.0.1');
$c = inet_ntop(inet_pton('::1'));
echo $a === '127.0.0.1' ? "long2ip\n" : "no-long2ip\n";
echo $b === 2130706433 ? "ip2long\n" : "no-ip2long\n";
echo $c === '::1' ? "roundtrip\n" : "no-roundtrip\n";
echo long2ip(ip2long('10.0.0.1')) === '10.0.0.1' ? "cycle\n" : "no-cycle\n";
--EXPECT--
long2ip
ip2long
roundtrip
cycle
