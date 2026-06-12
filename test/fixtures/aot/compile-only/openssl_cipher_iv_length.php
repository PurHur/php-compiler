<?php
echo openssl_cipher_iv_length('aes-256-cbc'), "\n";
echo (openssl_cipher_iv_length('not-a-real-cipher-method') === false ? "false\n" : "bad\n");
