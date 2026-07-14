<?php
// php_uname(null) must coerce to string and return full uname (ext/standard/info.c, #18970).
$full = php_uname('a');
$null = php_uname(null);
echo $null === $full ? "null_matches_a\n" : "null_mismatch\n";
echo '' !== $null ? "non_empty\n" : "empty\n";
