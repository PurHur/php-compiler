--TEST--
Stdlib: user stream wrapper read when stream_eof is true before first read (userspace.c, #9162)
--FILE--
<?php
class W {
    public function stream_open($path, $mode, $options, &$opened_path) {
        $opened_path = $path;
        return true;
    }
    public function stream_read($count) { return 'hi'; }
    public function stream_eof() { return true; }
    public function stream_stat() { return []; }
}
stream_wrapper_register('test', 'W');
var_export(file_get_contents('test://foo'));
echo "\n";
--EXPECT--
'hi'
