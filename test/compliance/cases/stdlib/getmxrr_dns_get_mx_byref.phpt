--TEST--
stdlib getmxrr/dns_get_mx by-ref $hosts — no cannot pass by reference Error (#12704, ext/standard/dns.c)
--FILE--
<?php
getmxrr('example.com', $hosts);
echo is_array($hosts) ? "getmxrr-array\n" : "getmxrr-fail\n";
dns_get_mx('example.com', $mx);
echo is_array($mx) ? "dns_get_mx-array\n" : "dns_get_mx-fail\n";
?>
--EXPECT--
getmxrr-array
dns_get_mx-array
