<?php
foreach (['inet_pton', 'inet_ntop'] as $n) {
    $r = new ReflectionFunction($n);
    foreach ($r->getParameters() as $p) {
        echo $n, ' $', $p->getName(), ':', ($p->hasType() ? (string) $p->getType() : 'none'), "\n";
    }
    echo $n, ' ret=', ($r->hasReturnType() ? (string) $r->getReturnType() : 'none'), "\n";
}
try {
    echo bin2hex(inet_pton(ip: '127.0.0.1')), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo bin2hex(inet_pton(ip_address: '127.0.0.1')), "\n";
} catch (Throwable $e) {
    echo 'legacy:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $bin = inet_pton('127.0.0.1');
    echo inet_ntop(ip: $bin), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $bin = inet_pton('127.0.0.1');
    echo inet_ntop(in_addr: $bin), "\n";
} catch (Throwable $e) {
    echo 'legacy_ntop:', get_class($e), ':', $e->getMessage(), "\n";
}
