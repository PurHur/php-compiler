--TEST--
assert_options(): invalid $option throws ValueError (#30524)
--FILE--
<?php
function ao_invalid_option_err($n, $m)
{
    echo 'E:', $m, "\n";
    return true;
}
set_error_handler('ao_invalid_option_err');
foreach ([0, 999, null] as $v) {
    echo '=== ';
    var_export($v);
    echo " ===\n";
    try {
        var_export(assert_options($v));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo "set invalid:\n";
try {
    var_export(assert_options(999, 1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'active=', var_export(assert_options(ASSERT_ACTIVE), true), "\n";
--EXPECT--
=== 0 ===
ValueError: assert_options(): Argument #1 ($option) must be an ASSERT_* constant
=== 999 ===
ValueError: assert_options(): Argument #1 ($option) must be an ASSERT_* constant
=== NULL ===
E:assert_options(): Passing null to parameter #1 ($option) of type int is deprecated
ValueError: assert_options(): Argument #1 ($option) must be an ASSERT_* constant
set invalid:
ValueError: assert_options(): Argument #1 ($option) must be an ASSERT_* constant
active=1
