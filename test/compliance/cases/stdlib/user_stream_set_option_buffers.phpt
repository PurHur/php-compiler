--TEST--
stdlib stream_set_write/read_buffer+blocking dispatch stream_set_option on user wrappers (#25999)
--FILE--
<?php
class BufWrap {
    public $context;
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_set_option($option, $arg1, $arg2) {
        echo "opt=$option|";
        return true;
    }
    public function stream_write($data) {
        return strlen($data);
    }
    public function stream_eof() {
        return false;
    }
    public function stream_read($count) {
        return '';
    }
}
stream_wrapper_register('uwbuf', BufWrap::class);
$f = fopen('uwbuf://x', 'w+');
echo 'wb='.var_export(stream_set_write_buffer($f, 0), true).'|';
echo 'rb='.var_export(stream_set_read_buffer($f, 0), true).'|';
echo 'blk='.var_export(stream_set_blocking($f, false), true)."\n";
fclose($f);
stream_wrapper_unregister('uwbuf');
--EXPECT--
opt=3|wb=0|opt=2|rb=0|opt=1|blk=true
