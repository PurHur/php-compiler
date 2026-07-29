<?php
/** Repro for #24491 — openssl_pkey_new Reflection + Zend named `options`. */
$r = new ReflectionFunction('openssl_pkey_new');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";
$cfg = ['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
try {
    $k = openssl_pkey_new(options: $cfg);
    echo 'options=', ($k === false ? 'false' : 'ok'), "\n";
} catch (Throwable $e) {
    echo 'options=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $k = openssl_pkey_new(configargs: $cfg);
    echo 'configargs=', ($k === false ? 'false' : 'ok'), "\n";
} catch (Throwable $e) {
    echo 'configargs=', get_class($e), ': ', $e->getMessage(), "\n";
}
