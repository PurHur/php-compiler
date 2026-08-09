--TEST--
unserialize() truncated O: emits E_WARNING + error_get_last under PROFILE=8.4 (#29204)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function ($n, $m) use (&$msgs) {
    $msgs[] = [$n, $m];
    return true;
});
$r = unserialize('O:8:"stdClass":1:{');
restore_error_handler();
echo 'r=';
var_export($r);
echo "\nmsgs=", json_encode($msgs), "\n";
error_clear_last();
$r2 = @unserialize('O:8:"stdClass":1:{');
echo 'r2=';
var_export($r2);
echo "\nlast_type=", (error_get_last()['type'] ?? 0), "\n";
echo 'last_msg=', (error_get_last()['message'] ?? ''), "\n";
--EXPECT--
r=false
msgs=[[2,"unserialize(): Error at offset 18 of 18 bytes"]]
r2=false
last_type=2
last_msg=unserialize(): Error at offset 18 of 18 bytes
