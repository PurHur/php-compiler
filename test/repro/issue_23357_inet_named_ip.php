<?php
// #23357 — inet_pton/inet_ntop/ip2long/long2ip/gethostbyaddr named $ip (php-src basic_functions.stub.php)
foreach (['inet_pton', 'inet_ntop', 'ip2long', 'long2ip', 'gethostbyaddr'] as $n) {
    $r = new ReflectionFunction($n);
    echo $n, ':', $r->getParameters()[0]->getName(), "\n";
}
echo bin2hex(inet_pton(ip: '127.0.0.1')), "\n";
echo ip2long(ip: '127.0.0.1'), "\n";
echo long2ip(ip: 2130706433), "\n";
$h = gethostbyaddr(ip: '127.0.0.1');
echo is_string($h) && '' !== $h ? "gethostbyaddr:ok\n" : "gethostbyaddr:bad\n";
try {
    inet_pton(ip_address: '127.0.0.1');
    echo "legacy_pton:NO_THROW\n";
} catch (Throwable $e) {
    echo 'legacy_pton:', $e->getMessage(), "\n";
}
try {
    ip2long(ip_address: '127.0.0.1');
    echo "legacy_ip2long:NO_THROW\n";
} catch (Throwable $e) {
    echo 'legacy_ip2long:', $e->getMessage(), "\n";
}
try {
    long2ip(proper_address: 2130706433);
    echo "legacy_long2ip:NO_THROW\n";
} catch (Throwable $e) {
    echo 'legacy_long2ip:', $e->getMessage(), "\n";
}
try {
    gethostbyaddr(ip_address: '127.0.0.1');
    echo "legacy_gethostbyaddr:NO_THROW\n";
} catch (Throwable $e) {
    echo 'legacy_gethostbyaddr:', $e->getMessage(), "\n";
}
try {
    long2ip([]);
    echo "long2ip_array:NO_THROW\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
