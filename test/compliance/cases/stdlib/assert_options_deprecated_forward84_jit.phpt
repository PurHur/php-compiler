--TEST--
assert_options + ASSERT_EXCEPTION E_DEPRECATED under PROFILE=8.4 (JIT, #29209)
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
assert_options(ASSERT_EXCEPTION, 1);
try {
    assert(false);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
restore_error_handler();
echo 'warns=', json_encode($msgs), "\n";
--EXPECT--
AssertionError
warns=["Constant ASSERT_EXCEPTION is deprecated","Function assert_options() is deprecated since 8.3"]
