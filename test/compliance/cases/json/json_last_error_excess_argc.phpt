--TEST--
json: json_last_error / json_last_error_msg excess argc → ArgumentCountError (#30591, ext/json/json.c)
--FILE--
<?php
foreach ([
    'json_last_error' => static fn () => json_last_error('x'),
    'json_last_error_msg' => static fn () => json_last_error_msg('x'),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_err=', json_last_error(), "\n";
echo 'ok_msg=', json_last_error_msg(), "\n";
--EXPECT--
json_last_error ArgumentCountError: json_last_error() expects exactly 0 arguments, 1 given
json_last_error_msg ArgumentCountError: json_last_error_msg() expects exactly 0 arguments, 1 given
ok_err=0
ok_msg=No error
