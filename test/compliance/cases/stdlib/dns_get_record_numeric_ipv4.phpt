--TEST--
stdlib dns_get_record() numeric IPv4 literal — empty A records (#12561, ext/standard/dns.c)
--FILE--
<?php
foreach (['127.0.0.1', '192.168.1.1', '10.0.0.1'] as $ip) {
    $records = dns_get_record($ip, DNS_A);
    echo $ip, ':', is_array($records) && [] === $records ? "empty\n" : "bad\n";
}
?>
--EXPECT--
127.0.0.1:empty
192.168.1.1:empty
10.0.0.1:empty
