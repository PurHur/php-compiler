--TEST--
AOT range() multibyte bounds warn under PROFILE=8.4 (#29203)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
error_clear_last();
$r = @range('あ', 'う');
$last = error_get_last();
echo 'count=', count($r), "\n";
echo 'ord=', ord($r[0]), "\n";
echo 'type=', ($last['type'] ?? 'null'), "\n";
echo 'msg=', ($last['message'] ?? 'null'), "\n";
--EXPECT--
count=1
ord=227
type=2
msg=range(): Argument #2 ($end) must be a single byte, subsequent bytes are ignored
