<?php

declare(strict_types=1);

if (!function_exists('openssl_get_cert_locations') || !function_exists('openssl_get_curve_names')) {
    echo "fail: missing openssl introspection builtins\n";
    exit(1);
}

$locations = openssl_get_cert_locations();
if (!\is_array($locations)) {
    echo "fail: cert locations not array\n";
    exit(1);
}

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
    if (!\array_key_exists($key, $locations)) {
        echo "fail: missing key $key\n";
        exit(1);
    }
}

if ('SSL_CERT_FILE' !== $locations['default_cert_file_env']) {
    echo "fail: default_cert_file_env\n";
    exit(1);
}

if ('SSL_CERT_DIR' !== $locations['default_cert_dir_env']) {
    echo "fail: default_cert_dir_env\n";
    exit(1);
}

if ('' === $locations['default_cert_file'] || '' === $locations['default_cert_dir']) {
    echo "fail: empty default cert paths\n";
    exit(1);
}

$curves = openssl_get_curve_names();
if (!\is_array($curves) || [] === $curves) {
    echo "fail: curve names empty\n";
    exit(1);
}

if (!\in_array('prime256v1', $curves, true)) {
    echo "fail: prime256v1 missing\n";
    exit(1);
}

echo "ok\n";
