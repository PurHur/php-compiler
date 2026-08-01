--TEST--
Stdlib: get_headers(file://) warns + false (#26383, ext/standard/head.c)
--FILE--
<?php
error_reporting(E_ALL);
$saw = false;
set_error_handler(function ($no, $msg) use (&$saw) {
    if ($no === E_WARNING) {
        $saw = true;
        echo "WARN:$msg\n";
        return true;
    }
    return false;
});
file_put_contents(__DIR__ . '/get_headers_file_warn_fixture.txt', 'x');
$h = get_headers('file://' . __DIR__ . '/get_headers_file_warn_fixture.txt');
@unlink(__DIR__ . '/get_headers_file_warn_fixture.txt');
echo 'saw=', $saw ? '1' : '0', ' result=', var_export($h, true), "\n";
?>
--EXPECT--
WARN:get_headers(): This function may only be used against URLs
saw=1 result=false
