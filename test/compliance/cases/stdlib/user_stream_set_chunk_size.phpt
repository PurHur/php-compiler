--TEST--
stdlib stream_set_chunk_size() on user wrappers returns previous size (streams.c, #26045)
--FILE--
<?php
class ChunkWrap {
    public $context;
    private $fp;
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        $this->fp = fopen('php://temp', 'r+');
        return true;
    }
    public function stream_eof() {
        return true;
    }
    public function stream_read($count) {
        return '';
    }
    public function stream_write($data) {
        return 0;
    }
}
stream_wrapper_register('uwchunk', ChunkWrap::class);
$f = fopen('uwchunk://x', 'r+');
echo 'first=', var_export(stream_set_chunk_size($f, 16), true), "\n";
echo 'second=', var_export(stream_set_chunk_size($f, 32), true), "\n";
try {
    stream_set_chunk_size($f, 0);
    echo "zero=ok\n";
} catch (ValueError $ex) {
    echo 'zero=', $ex->getMessage(), "\n";
}
fclose($f);
stream_wrapper_unregister('uwchunk');
--EXPECT--
first=8192
second=16
zero=stream_set_chunk_size(): Argument #2 ($size) must be greater than 0
