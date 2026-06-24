--TEST--
stdlib dns_get_record() NXDOMAIN returns empty array (issue #11355, ext/standard/dns.c)
--FILE--
<?php
$result = @dns_get_record('invalid.invalid', DNS_A);
echo is_array($result) && [] === $result ? "empty_array\n" : "fail\n";
--EXPECT--
empty_array
