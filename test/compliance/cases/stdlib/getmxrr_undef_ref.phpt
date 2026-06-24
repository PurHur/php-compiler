--TEST--
stdlib getmxrr() undeclared by-ref $hosts — no undefined-variable warning (#11182, ext/standard/dns.c)
--FILE--
<?php
getmxrr('example.com', $hosts);
echo is_array($hosts) ? "hosts-array\n" : "hosts-not-array\n";
echo count($hosts) >= 1 ? "hosts-nonempty\n" : "hosts-empty\n";
?>
--EXPECT--
hosts-array
hosts-nonempty
