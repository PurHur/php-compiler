<?php
// Zend parity: ext/standard/info.c php_get_uname() — unknown mode falls through to full uname (#9276).
$full = php_uname('a');
$invalid = php_uname('z');
echo $invalid === $full ? "invalid_matches_a\n" : "mismatch\n";
echo '' !== $invalid ? "non_empty\n" : "empty\n";
