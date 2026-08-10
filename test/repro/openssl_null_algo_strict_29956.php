<?php
/**
 * #29956 — openssl_* null cipher/digest algo under strict_types → TypeError (php-src openssl.stub.php).
 * AOT: uncaught TypeError (try/catch+TypeError abort is a separate LLVM CFG issue also seen with strlen(null)).
 */
declare(strict_types=1);
openssl_encrypt('plain', null, '0123456789abcdef', 0, '1234567890123456');
