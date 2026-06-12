<?php

declare(strict_types=1);

echo 'function_exists: ', function_exists('openssl_cipher_key_length') ? 'true' : 'false', PHP_EOL;
var_dump(openssl_cipher_key_length('aes-256-cbc'));
var_dump(openssl_cipher_key_length('not-a-real-cipher-method'));
