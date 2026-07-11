--TEST--
stdlib filetype() missing path records error_get_last() even when silenced (@) (#10548)
--FILE--
<?php
error_clear_last();
$ret = @filetype('/no/such/phpc-filetype-path');
var_dump($ret);
$last = error_get_last();
echo null === $last ? "noerr\n" : $last['message']."\n";
--EXPECT--
bool(false)
filetype(): Lstat failed for /no/such/phpc-filetype-path

