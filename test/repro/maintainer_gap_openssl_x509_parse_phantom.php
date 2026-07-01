<?php

declare(strict_types=1);

if (extension_loaded('openssl') && !\function_exists('openssl_x509_parse')) {
    fwrite(STDERR, "fail: extension_loaded('openssl') true but openssl_x509_parse() missing\n");
    exit(1);
}

echo "ok\n";
