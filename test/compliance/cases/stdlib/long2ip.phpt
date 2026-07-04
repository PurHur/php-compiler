--TEST--
stdlib long2ip() / ip2long() / inet_ntop() / inet_pton() (issue #3225)
--FILE--
<?php
foreach (['long2ip', 'ip2long', 'inet_ntop', 'inet_pton'] as $f) {
    if (!function_exists($f)) {
        fwrite(STDERR, "MISSING: $f\n");
        exit(1);
    }
}
echo long2ip(2130706433), "\n";
echo ip2long('127.0.0.1'), "\n";
echo inet_ntop(inet_pton('::1')), "\n";
echo long2ip(ip2long('192.168.1.1')), "\n";
echo ip2long('not-an-ip') === false ? "bad-ip\n" : "ok-ip\n";
echo long2ip(-1) === '255.255.255.255' ? "wrap-neg\n" : "no-wrap-neg\n";
echo long2ip(4294967296) === '0.0.0.0' ? "wrap-over\n" : "no-wrap-over\n";
$bin = inet_pton('127.0.0.1');
echo is_string($bin) && strlen($bin) === 4 ? "v4-bin\n" : "no-v4-bin\n";
$bin6 = inet_pton('::1');
echo is_string($bin6) && strlen($bin6) === 16 ? "v6-bin\n" : "no-v6-bin\n";
--EXPECT--
127.0.0.1
2130706433
::1
192.168.1.1
bad-ip
wrap-neg
wrap-over
v4-bin
v6-bin
