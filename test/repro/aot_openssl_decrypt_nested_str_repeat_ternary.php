<?php
// Nested str_repeat key/iv + later ?: must not wire both openssl_decrypt args to the IV (#35878).
$c = openssl_encrypt("hi", "AES-128-CBC", str_repeat("k", 16), 0, str_repeat("i", 16));
$p = openssl_decrypt($c, "AES-128-CBC", str_repeat("k", 16), 0, str_repeat("i", 16));
echo $p === false ? "false\n" : "$p\n";
$c2 = openssl_encrypt("hi", "AES-128-CBC", str_repeat("k", 16), 0, str_repeat("i", 16));
$p2 = openssl_decrypt($c2, "AES-128-CBC", str_repeat("k", 16), 0, str_repeat("i", 16));
echo var_export($p2, true), "\n";
