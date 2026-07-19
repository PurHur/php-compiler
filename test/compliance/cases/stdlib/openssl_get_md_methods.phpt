--TEST--
stdlib openssl_get_md_methods() — lists sha256 (#6228)
--FILE--
<?php
echo function_exists('openssl_get_md_methods') ? "exists\n" : "missing\n";
$mds = openssl_get_md_methods();
echo in_array('sha256', $mds, true) ? "has_sha256\n" : "missing_sha256\n";
--EXPECT--
exists
has_sha256
