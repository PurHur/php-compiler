--TEST--
stdlib stream_wrapper_register() — TypeError when wrapper class is unknown (#12534, php-src userspace.c)
--FILE--
<?php
try {
    stream_wrapper_register('probe', 'NotAClass');
    echo "registered\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
class ValidStream {
    public function stream_open($path, $mode, $options) { return true; }
    public function stream_read($count) { return ''; }
    public function stream_eof() { return true; }
}
var_export(stream_wrapper_register('valid', 'ValidStream'));
echo "\n";
--EXPECT--
stream_wrapper_register(): Argument #2 ($class) must be a valid class name, NotAClass given
true
