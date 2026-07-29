<?php
/**
 * #24663 — openssl_x509_parse / openssl_csr_new / openssl_pkcs7_sign Reflection + named args
 * must match php-src ext/openssl/openssl.stub.php (not InternalArgInfo pre-stub names).
 */
declare(strict_types=1);

foreach (['openssl_x509_parse', 'openssl_csr_new', 'openssl_pkcs7_sign'] as $f) {
    $n = [];
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        $n[] = $p->getName();
    }
    echo $f, ': ', implode(',', $n), "\n";
}

try {
    openssl_x509_parse(certificate: 'x', short_names: true);
    echo "parse_named=ok\n";
} catch (Throwable $e) {
    echo 'parse_named=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    openssl_x509_parse(x509: 'x');
    echo "parse_stale=ok\n";
} catch (Throwable $e) {
    echo 'parse_stale=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $pk = null;
    openssl_csr_new(distinguished_names: ['CN' => 'test'], private_key: $pk);
    echo "csr_named=ok\n";
} catch (Throwable $e) {
    echo 'csr_named=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    openssl_csr_new(dn: ['CN' => 'test'], privkey: $pk);
    echo "csr_stale=ok\n";
} catch (Throwable $e) {
    echo 'csr_stale=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    openssl_pkcs7_sign(input_filename: 'in', output_filename: 'out', certificate: 'c', private_key: 'k', headers: []);
    echo "pkcs7_named=ok\n";
} catch (Throwable $e) {
    echo 'pkcs7_named=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    openssl_pkcs7_sign(infile: 'in', outfile: 'out', signcert: 'c', signkey: 'k', headers: []);
    echo "pkcs7_stale=ok\n";
} catch (Throwable $e) {
    echo 'pkcs7_stale=', get_class($e), ': ', $e->getMessage(), "\n";
}
