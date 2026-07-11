--TEST--
stdlib filesize() missing path records error_get_last() even when silenced (@) (#10547)
--FILE--
<?php
error_clear_last();
$ret = @filesize('/no/such/phpc-filesize-path');
var_dump($ret);
$last = error_get_last();
echo null === $last ? "noerr\n" : $last['message']."\n";
--EXPECT--
bool(false)
filesize(): stat failed for /no/such/phpc-filesize-path

