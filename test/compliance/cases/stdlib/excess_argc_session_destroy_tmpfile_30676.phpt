--TEST--
stdlib: session_destroy / tmpfile excess argc → ArgumentCountError (#30676)
--FILE--
<?php
foreach (['session_destroy', 'tmpfile'] as $name) {
    try {
        $name(1);
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
error_reporting(E_ALL & ~E_WARNING);
session_destroy();
echo "session_destroy_ok\n";
$h = tmpfile();
echo (false !== $h) ? "tmpfile_ok\n" : "tmpfile_fail\n";
?>
--EXPECT--
session_destroy ArgumentCountError: session_destroy() expects exactly 0 arguments, 1 given
tmpfile ArgumentCountError: tmpfile() expects exactly 0 arguments, 1 given
session_destroy_ok
tmpfile_ok
