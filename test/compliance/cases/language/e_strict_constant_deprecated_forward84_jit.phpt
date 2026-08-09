--TEST--
E_STRICT constant fetch E_DEPRECATED under PROFILE=8.4 (JIT, #29229)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function ($n, $m) use (&$msgs) {
    $msgs[] = $m;
    return true;
});
echo 'val=', E_STRICT, "\n";
echo 'warns=', json_encode($msgs), "\n";
--EXPECT--
val=2048
warns=["Constant E_STRICT is deprecated"]
