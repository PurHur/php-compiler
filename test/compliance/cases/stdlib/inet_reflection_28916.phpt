--TEST--
stdlib inet_pton/inet_ntop Reflection stubs (#28916, basic_functions.stub.php)
--FILE--
<?php
foreach (['inet_pton', 'inet_ntop'] as $n) {
    $r = new ReflectionFunction($n);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = $p->getName() . ':' . ($p->hasType() ? (string) $p->getType() : '?');
    }
    echo $n, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped',
        ' [', implode(', ', $ps), ']', PHP_EOL;
}
try {
    echo bin2hex(inet_pton(ip: '127.0.0.1')), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
try {
    echo bin2hex(inet_pton(ip_address: '127.0.0.1')), PHP_EOL;
} catch (Throwable $e) {
    echo 'legacy:', get_class($e), ':', $e->getMessage(), PHP_EOL;
}
try {
    $bin = inet_pton('127.0.0.1');
    echo inet_ntop(ip: $bin), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
try {
    $bin = inet_pton('127.0.0.1');
    echo inet_ntop(in_addr: $bin), PHP_EOL;
} catch (Throwable $e) {
    echo 'legacy_ntop:', get_class($e), ':', $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
inet_pton ret=string|false [ip:string]
inet_ntop ret=string|false [ip:string]
7f000001
legacy:Error:Unknown named parameter $ip_address
127.0.0.1
legacy_ntop:Error:Unknown named parameter $in_addr
