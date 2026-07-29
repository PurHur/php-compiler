<?php
/** Repro for #24492 — openssl_pkey_export Reflection + Zend named output/options. */
$r = new ReflectionFunction('openssl_pkey_export');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";
$k = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($k === false) {
    echo "keygen_failed\n";
    exit(0);
}
$out = '';
try {
    $ok = openssl_pkey_export(key: $k, output: $out);
    echo 'output=', ($ok ? 'ok' : 'fail'), ' len=', strlen($out), "\n";
} catch (Throwable $e) {
    echo 'output=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $ok = openssl_pkey_export(key: $k, out: $out);
    echo 'out=', ($ok ? 'ok' : 'fail'), "\n";
} catch (Throwable $e) {
    echo 'out=', get_class($e), ': ', $e->getMessage(), "\n";
}
$rf = new ReflectionFunction('openssl_pkey_export_to_file');
$names2 = [];
foreach ($rf->getParameters() as $p) {
    $names2[] = $p->getName();
}
echo 'to_file_params=', implode(',', $names2), "\n";
$file = sys_get_temp_dir().'/phpc_pkey_export_24492.pem';
@unlink($file);
try {
    $ok = openssl_pkey_export_to_file(key: $k, output_filename: $file);
    echo 'to_file=', ($ok && is_file($file) ? 'ok' : 'fail'), "\n";
} catch (Throwable $e) {
    echo 'to_file=', get_class($e), ': ', $e->getMessage(), "\n";
}
@unlink($file);
try {
    openssl_pkey_export_to_file(key: $k, outfilename: $file);
    echo "legacy to_file accepted\n";
} catch (Throwable $e) {
    echo 'legacy_to_file=', $e->getMessage(), "\n";
}
