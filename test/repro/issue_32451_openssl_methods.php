<?php
// #32451 leftover Type ABI drop — user-script openssl_get_*_methods stay PHP helpers.
echo count(openssl_get_cipher_methods()) >= 100 ? "ciphers_ok\n" : "ciphers_bad\n";
echo count(openssl_get_md_methods()) >= 10 ? "md_ok\n" : "md_bad\n";
