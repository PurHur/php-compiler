--TEST--
openssl_pkey_export Reflection + Zend named output/options (VM, issue #24492)
--FILE--
<?php
$r = new ReflectionFunction('openssl_pkey_export');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), PHP_EOL;
$k = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$out = '';
$ok = openssl_pkey_export(key: $k, output: $out);
echo 'output=', ($ok ? 'ok' : 'fail'), ' len=', (strlen($out) > 0 ? 'gt0' : '0'), PHP_EOL;
try {
    openssl_pkey_export(key: $k, out: $out);
    echo "legacy out accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    openssl_pkey_export(key: $k, output: $out, config_args: []);
    echo "legacy config_args accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
$rf = new ReflectionFunction('openssl_pkey_export_to_file');
$names2 = [];
foreach ($rf->getParameters() as $p) {
    $names2[] = $p->getName();
}
echo 'to_file=', implode(',', $names2), PHP_EOL;
--EXPECT--
params=key,output,passphrase,options
output=ok len=gt0
Unknown named parameter $out
Unknown named parameter $config_args
to_file=key,output_filename,passphrase,options
