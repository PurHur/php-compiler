--TEST--
AOT long2ip() / ip2long() / inet_pton() / inet_ntop() (issue #3225)
--FILE--
<?php
echo long2ip(2130706433) === '127.0.0.1' ? "l2i\n" : "no-l2i\n";
echo ip2long('127.0.0.1') === 2130706433 ? "i2l\n" : "no-i2l\n";
echo inet_ntop(inet_pton('::1')) === '::1' ? "pton\n" : "no-pton\n";
echo long2ip(-1) === '255.255.255.255' ? "edge\n" : "no-edge\n";
--EXPECT--
l2i
i2l
pton
edge
