<?php
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$path = sys_get_temp_dir() . '/phpc-34755-export-' . getmypid() . '.pem';
$ok = openssl_pkey_export_to_file($key, $path);
echo ($ok ? 'true' : 'false'), "\n";
$pem = @file_get_contents($path);
@unlink($path);
echo (is_string($pem) && str_contains($pem, 'BEGIN') ? 'pem_ok' : 'pem_bad'), "\n";
