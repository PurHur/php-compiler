--TEST--
openssl_pkey_new Reflection + Zend named options (VM, issue #24491)
--FILE--
<?php
$r = new ReflectionFunction('openssl_pkey_new');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), PHP_EOL;
$cfg = ['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$k = openssl_pkey_new(options: $cfg);
echo 'options=', ($k === false ? 'false' : 'ok'), PHP_EOL;
try {
    openssl_pkey_new(configargs: $cfg);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
params=options
options=ok
Unknown named parameter $configargs
