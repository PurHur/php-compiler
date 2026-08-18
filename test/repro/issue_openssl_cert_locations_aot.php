<?php
/**
 * openssl_get_cert_locations() JIT/AOT (#32388, #6560 leftover).
 * php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_get_cert_locations)
 * / X509_get_default_cert_file / openssl.cafile.
 */
$locations = openssl_get_cert_locations();
echo is_array($locations) ? "array:1\n" : "array:0\n";
foreach ([
    'default_cert_file',
    'default_cert_file_env',
    'default_cert_dir',
    'default_cert_dir_env',
    'default_private_dir',
    'default_default_cert_area',
    'ini_cafile',
    'ini_capath',
] as $key) {
    echo array_key_exists($key, $locations) ? "$key:1\n" : "$key:0\n";
}
echo ($locations['default_cert_file_env'] ?? null) === 'SSL_CERT_FILE' ? "env_file:1\n" : "env_file:0\n";
echo ($locations['default_cert_dir_env'] ?? null) === 'SSL_CERT_DIR' ? "env_dir:1\n" : "env_dir:0\n";
echo ($locations['default_cert_file'] ?? '') !== '' ? "file_ne:1\n" : "file_ne:0\n";
echo ($locations['default_cert_dir'] ?? '') !== '' ? "dir_ne:1\n" : "dir_ne:0\n";
