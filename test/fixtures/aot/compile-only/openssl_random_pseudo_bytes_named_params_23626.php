<?php
// AOT lint-only: openssl_random_pseudo_bytes Zend stub named params (#23626)
$strong = false;
$bytes = openssl_random_pseudo_bytes(length: 4, strong_result: $strong);
echo strlen($bytes), ' ', (int)$strong, "\n";
