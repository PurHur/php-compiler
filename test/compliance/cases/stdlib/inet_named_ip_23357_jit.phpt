--TEST--
JIT: inet_pton/ip2long/long2ip named $ip (#23357, basic_functions.stub.php)
--FILE--
<?php
echo bin2hex(inet_pton(ip: '127.0.0.1')), PHP_EOL;
echo ip2long(ip: '127.0.0.1'), PHP_EOL;
echo long2ip(ip: 2130706433), PHP_EOL;
try {
    ip2long(ip_address: '127.0.0.1');
    echo 'legacy_ip2long:NO_THROW', PHP_EOL;
} catch (Throwable $e) {
    echo 'legacy_ip2long:', $e->getMessage(), PHP_EOL;
}
try {
    long2ip(proper_address: 2130706433);
    echo 'legacy_long2ip:NO_THROW', PHP_EOL;
} catch (Throwable $e) {
    echo 'legacy_long2ip:', $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
7f000001
2130706433
127.0.0.1
legacy_ip2long:Unknown named parameter $ip_address
legacy_long2ip:Unknown named parameter $proper_address
