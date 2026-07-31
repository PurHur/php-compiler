--TEST--
stdlib fstat() on user wrappers dispatches stream_stat (userspace.c, #25998)
--FILE--
<?php
class StatWrap {
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
        return [
            'dev' => 1,
            'ino' => 2,
            'mode' => 0100644,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 7,
            'atime' => 10,
            'mtime' => 11,
            'ctime' => 12,
            'blksize' => 4096,
            'blocks' => 1,
        ];
    }
}
stream_wrapper_register('uwstat', StatWrap::class);
$f = fopen('uwstat://x', 'r');
$st = fstat($f);
echo var_export($st !== false, true);
if (is_array($st)) {
    echo '|size=', $st['size'];
}
echo "\n";
fclose($f);
stream_wrapper_unregister('uwstat');
--EXPECT--
true|size=7
