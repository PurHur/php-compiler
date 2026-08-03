<?php
/**
 * #27265 — AOT openssl_encrypt() AES-128-CBC must match Zend (tag out-param ABI + EVP leaf).
 */
$c = openssl_encrypt('hi', 'AES-128-CBC', str_repeat('k', 16), 0, str_repeat('i', 16));
$expect = 'JrDjGHYK9CvRr0L0p1wckA==';
echo (is_string($c) && $c === $expect) ? "ok\n" : "bad\n";
