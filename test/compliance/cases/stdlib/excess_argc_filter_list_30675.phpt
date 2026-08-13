--TEST--
stdlib: filter_list() excess argc → ArgumentCountError (#30675)
--FILE--
<?php
try {
    filter_list('x');
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$ok = filter_list();
echo is_array($ok) && in_array('int', $ok, true) ? "filter_list_ok\n" : "filter_list_fail\n";
?>
--EXPECT--
ArgumentCountError: filter_list() expects exactly 0 arguments, 1 given
filter_list_ok
