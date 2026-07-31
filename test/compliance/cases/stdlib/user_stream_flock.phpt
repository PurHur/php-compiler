--TEST--
stdlib flock() on user wrappers dispatches stream_lock (userspace.c, #25995)
--FILE--
<?php
class LockWrap {
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
    public function stream_lock($operation) {
        return true;
    }
}
stream_wrapper_register('uwlock', LockWrap::class);
$f = fopen('uwlock://x', 'r');
echo 'supports=', var_export(stream_supports_lock($f), true), "\n";
echo 'flock=', var_export(flock($f, LOCK_EX), true), "\n";
fclose($f);
stream_wrapper_unregister('uwlock');
--EXPECT--
supports=true
flock=true
