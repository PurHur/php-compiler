--TEST--
stdlib stream_set_timeout() on user wrappers dispatches stream_set_option (userspace.c, #25996)
--FILE--
<?php
class TimeoutWrap {
    public $context;
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_read($count) {
        return '';
    }
    public function stream_eof() {
        return true;
    }
    public function stream_stat() {
        return [];
    }
    public function stream_set_option($option, $arg1, $arg2) {
        return true;
    }
}
stream_wrapper_register('uwto', TimeoutWrap::class);
$f = fopen('uwto://x', 'r');
echo var_export(stream_set_timeout($f, 1, 0), true), "\n";
fclose($f);
stream_wrapper_unregister('uwto');
--EXPECT--
true
