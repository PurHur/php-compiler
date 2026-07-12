--TEST--
stdlib dns_get_record() invalid type ValueError message (#18147, ext/standard/dns.c)
--FILE--
<?php
try {
    dns_get_record('example.com', 99999);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
dns_get_record(): Argument #2 ($type) must be a DNS_* constant
