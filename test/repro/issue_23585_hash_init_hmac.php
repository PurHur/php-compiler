<?php
/**
 * Repro #23585 — hash_init arity / HASH_HMAC / Reflection vs Zend.
 */
$rf = new ReflectionFunction('hash_init');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName()
        .':'
        .($p->hasType() ? (string) $p->getType() : '?')
        .($p->isOptional() ? '?' : '');
}
echo 'params=', implode(',', $names), "\n";
echo 'count=', $rf->getNumberOfParameters(), "\n";
echo defined('HASH_HMAC') ? ('HMAC='.HASH_HMAC) : 'HMAC_MISSING', "\n";

try {
    $c = hash_init('sha256', 0);
    hash_update($c, 'msg');
    echo '2arg=', hash_final($c), "\n";
} catch (Throwable $e) {
    echo '2arg=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $c = hash_init(algo: 'sha256', flags: HASH_HMAC, key: 'secret');
    hash_update($c, 'msg');
    echo 'hmac=', hash_final($c), "\n";
    echo 'ref=', hash_hmac('sha256', 'msg', 'secret'), "\n";
} catch (Throwable $e) {
    echo 'hmac=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    hash_init('sha256', HASH_HMAC);
    echo "emptykey=OK\n";
} catch (Throwable $e) {
    echo 'emptykey=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    hash_init('adler32', HASH_HMAC, 'k');
    echo "noncrypto=OK\n";
} catch (Throwable $e) {
    echo 'noncrypto=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    hash_init('sha256', 0, '', 1);
    echo "badopt=OK\n";
} catch (Throwable $e) {
    echo 'badopt=', get_class($e), ':', $e->getMessage(), "\n";
}
