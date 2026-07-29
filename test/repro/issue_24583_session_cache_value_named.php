<?php
// Issue #24583 — session_cache_limiter/expire Zend stub named params (ext/session/session.stub.php).
// Named setters before any output so headers-sent does not warn.
try {
    session_cache_limiter(value: 'nocache');
    echo "limiter_value_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    session_cache_expire(value: 240);
    echo 'expire=', session_cache_expire(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    session_cache_limiter(new_cache_limiter: 'public');
    echo "limiter_legacy_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    session_cache_expire(new_cache_expire: 120);
    echo "expire_legacy_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
foreach (['session_cache_limiter', 'session_cache_expire'] as $f) {
    $rf = new ReflectionFunction($f);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $f, ':', implode(',', $names), "\n";
}
