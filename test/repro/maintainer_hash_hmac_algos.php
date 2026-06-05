<?php
// Zend parity: ext/hash/hash.c hash_hmac_algos() (#6229).
echo function_exists('hash_hmac_algos') ? "hash_hmac_algos: yes\n" : "hash_hmac_algos: no\n";
$algos = hash_hmac_algos();
echo in_array('sha256', $algos, true) ? "has_sha256: yes\n" : "has_sha256: no\n";
echo in_array('md5', $algos, true) ? "has_md5: yes\n" : "has_md5: no\n";
echo in_array('sha1', $algos, true) ? "has_sha1: yes\n" : "has_sha1: no\n";
