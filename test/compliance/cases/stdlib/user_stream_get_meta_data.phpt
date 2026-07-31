--TEST--
stdlib stream_get_meta_data() on user wrappers reports user-space (userspace.c, #25993)
--FILE--
<?php
class MetaWrap {
    public $context;
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_read($count) {
        return 'hi';
    }
    public function stream_eof() {
        return true;
    }
    public function stream_stat() {
        return [];
    }
}
stream_wrapper_register('uwmeta', MetaWrap::class);
$f = fopen('uwmeta://x', 'r');
$m = stream_get_meta_data($f);
echo $m['wrapper_type'], '|', $m['stream_type'], "\n";
fclose($f);
stream_wrapper_unregister('uwmeta');
--EXPECT--
user-space|user-space
