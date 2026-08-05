<?php
/**
 * Issue #27685 — openssl_pkey_derive Reflection arity/names/return + named args.
 * php-src: ext/openssl/openssl.stub.php
 */
$r = new ReflectionFunction('openssl_pkey_derive');
echo 'argc=', $r->getNumberOfParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', ($p->hasType() ? (string) $p->getType() : '?'), ($p->isOptional() ? ' opt' : ''), PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
try {
    openssl_pkey_derive(public_key: 'x', private_key: 'y');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
