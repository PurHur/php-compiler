<?php
// Repro #20287 — openssl_pkey_export_to_file PEM round-trip
declare(strict_types=1);

if (!function_exists('openssl_pkey_export_to_file')) {
    fwrite(STDERR, "fail: missing openssl_pkey_export_to_file\n");
    exit(1);
}

$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    fwrite(STDERR, "fail: pkey_new\n");
    exit(1);
}

$pem = '';
if (!openssl_pkey_export($key, $pem) || $pem === '') {
    fwrite(STDERR, "fail: pkey_export\n");
    exit(1);
}

$dir = sys_get_temp_dir().'/phpc-20287-'.getmypid();
mkdir($dir);
$file = $dir.'/key.pem';
if (!openssl_pkey_export_to_file($key, $file) || !is_file($file)) {
    fwrite(STDERR, "fail: export_to_file\n");
    exit(1);
}
if (file_get_contents($file) !== $pem) {
    fwrite(STDERR, "fail: PEM mismatch vs openssl_pkey_export\n");
    exit(1);
}

$enc = $dir.'/enc.pem';
if (!openssl_pkey_export_to_file($key, $enc, 'secret')) {
    fwrite(STDERR, "fail: export_to_file passphrase\n");
    exit(1);
}
$encBody = file_get_contents($enc);
if ($encBody === false || $encBody === '' || !str_contains($encBody, 'BEGIN')) {
    fwrite(STDERR, "fail: encrypted file empty\n");
    exit(1);
}
if (false === openssl_pkey_get_private($encBody, 'secret')) {
    fwrite(STDERR, "fail: cannot load encrypted PEM\n");
    exit(1);
}

@unlink($file);
@unlink($enc);
@rmdir($dir);
echo "ok\n";
