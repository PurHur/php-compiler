--TEST--
AOT php_uname() unknown mode returns full uname string (#9276)
--FILE--
<?php
$full = php_uname('a');
$invalid = php_uname('z');
echo $invalid === $full ? "invalid_matches_a\n" : "mismatch\n";
echo '' !== $invalid ? "non_empty\n" : "empty\n";
--EXPECT--
invalid_matches_a
non_empty
