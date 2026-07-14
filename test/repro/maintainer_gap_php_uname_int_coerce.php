<?php
// php_uname(int) must coerce to string; invalid mode char returns full uname (#18970).
$full = php_uname('a');
$int = php_uname(123);
echo $int === $full ? "int_matches_a\n" : "int_mismatch\n";
echo '' !== $int ? "non_empty\n" : "empty\n";
