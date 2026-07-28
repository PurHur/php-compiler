<?php
/** Repro for #24365 — openssl_digest/sign/verify Reflection + Zend named args. */
foreach (['openssl_digest', 'openssl_sign', 'openssl_verify'] as $f) {
    echo $f, ':';
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo "\n";
}
try {
    echo openssl_digest(data: 'x', digest_algo: 'sha256', binary: false), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    openssl_digest(data: 'x', method: 'sha256', raw_output: false);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
