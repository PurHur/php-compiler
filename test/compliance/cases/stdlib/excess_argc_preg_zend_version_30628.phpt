--TEST--
stdlib: preg_last_error / preg_last_error_msg / zend_version excess argc → ArgumentCountError (#30628)
--FILE--
<?php
foreach ([
    'preg_last_error' => static fn () => preg_last_error('x'),
    'preg_last_error_msg' => static fn () => preg_last_error_msg('x'),
    'zend_version' => static fn () => zend_version('x'),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_err=', preg_last_error(), "\n";
echo 'ok_msg=', preg_last_error_msg(), "\n";
echo 'ok_zv=', is_string(zend_version()) ? 'string' : 'other', "\n";
--EXPECT--
preg_last_error ArgumentCountError: preg_last_error() expects exactly 0 arguments, 1 given
preg_last_error_msg ArgumentCountError: preg_last_error_msg() expects exactly 0 arguments, 1 given
zend_version ArgumentCountError: zend_version() expects exactly 0 arguments, 1 given
ok_err=0
ok_msg=No error
ok_zv=string
