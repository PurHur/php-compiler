--TEST--
openssl_digest/sign/verify Reflection + Zend named args (VM, issue #24365)
--FILE--
<?php
foreach (['openssl_digest', 'openssl_sign', 'openssl_verify'] as $f) {
    echo $f, ':';
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo PHP_EOL;
}
echo openssl_digest(data: 'x', digest_algo: 'sha256', binary: false), PHP_EOL;
try {
    openssl_digest(data: 'x', method: 'sha256', raw_output: false);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
openssl_digest: data digest_algo binary
openssl_sign: data signature private_key algorithm
openssl_verify: data signature public_key algorithm
2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881
Unknown named parameter $method
