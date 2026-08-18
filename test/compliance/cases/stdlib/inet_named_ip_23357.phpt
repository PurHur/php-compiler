--TEST--
stdlib inet_pton/ip2long/long2ip/gethostbyaddr named $ip (#23357, basic_functions.stub.php)
--FILE--
<?php
foreach (['inet_pton', 'inet_ntop', 'ip2long', 'long2ip', 'gethostbyaddr'] as $n) {
    $r = new ReflectionFunction($n);
    $p = $r->getParameters()[0];
    echo $n, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped',
        ' [', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?', ']', PHP_EOL;
}
echo bin2hex(inet_pton(ip: '127.0.0.1')), PHP_EOL;
echo ip2long(ip: '127.0.0.1'), PHP_EOL;
echo long2ip(ip: 2130706433), PHP_EOL;
$h = gethostbyaddr(ip: '127.0.0.1');
echo is_string($h) && '' !== $h ? 'gethostbyaddr:ok' : 'gethostbyaddr:bad', PHP_EOL;
try {
    inet_pton(ip_address: '127.0.0.1');
    echo 'legacy_pton:NO_THROW', PHP_EOL;
} catch (Throwable $e) {
    echo 'legacy_pton:', $e->getMessage(), PHP_EOL;
}
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
try {
    gethostbyaddr(ip_address: '127.0.0.1');
    echo 'legacy_gethostbyaddr:NO_THROW', PHP_EOL;
} catch (Throwable $e) {
    echo 'legacy_gethostbyaddr:', $e->getMessage(), PHP_EOL;
}
try {
    long2ip([]);
    echo 'long2ip_array:NO_THROW', PHP_EOL;
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
inet_pton ret=string|false [ip:string]
inet_ntop ret=string|false [ip:string]
ip2long ret=int|false [ip:string]
long2ip ret=string|false [ip:int]
gethostbyaddr ret=string|false [ip:string]
7f000001
2130706433
127.0.0.1
gethostbyaddr:ok
legacy_pton:Unknown named parameter $ip_address
legacy_ip2long:Unknown named parameter $ip_address
legacy_long2ip:Unknown named parameter $proper_address
legacy_gethostbyaddr:Unknown named parameter $ip_address
long2ip(): Argument #1 ($ip) must be of type int, array given
