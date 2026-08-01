--TEST--
password_get_info/password_needs_rehash named hash/algo arguments (JIT, issue #23292)
--FILE--
<?php
$h = password_hash('secret', PASSWORD_DEFAULT);
$info = password_get_info(hash: $h);
echo isset($info['algoName']) ? "info_ok\n" : "info_fail\n";
echo password_needs_rehash(hash: $h, algo: PASSWORD_DEFAULT) ? "rehash_yes\n" : "rehash_no\n";
--EXPECT--
info_ok
rehash_no
