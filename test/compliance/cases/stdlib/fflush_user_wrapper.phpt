--TEST--
stdlib fflush() on user stream wrappers (#25986)
--FILE--
<?php
class FfUserWrap {
    public $data = '';
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_write($data) {
        $this->data .= $data;
        return strlen($data);
    }
    public function stream_flush() {
        return true;
    }
    public function stream_eof() {
        return true;
    }
    public function stream_stat() {
        return [];
    }
}
class FfUserWrapFail {
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_write($data) {
        return strlen($data);
    }
    public function stream_flush() {
        return false;
    }
    public function stream_eof() {
        return true;
    }
    public function stream_stat() {
        return [];
    }
}
class FfUserWrapAbsent {
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_write($data) {
        return strlen($data);
    }
    public function stream_eof() {
        return true;
    }
    public function stream_stat() {
        return [];
    }
}

@stream_wrapper_unregister('ffuser');
stream_wrapper_register('ffuser', FfUserWrap::class);
$h = fopen('ffuser://x', 'w');
fwrite($h, 'hi');
echo fflush($h) ? '1' : '0', "\n";
fclose($h);
stream_wrapper_unregister('ffuser');

@stream_wrapper_unregister('fffail');
stream_wrapper_register('fffail', FfUserWrapFail::class);
$h2 = fopen('fffail://x', 'w');
fwrite($h2, 'x');
echo fflush($h2) ? '1' : '0', "\n";
fclose($h2);
stream_wrapper_unregister('fffail');

@stream_wrapper_unregister('ffabs');
stream_wrapper_register('ffabs', FfUserWrapAbsent::class);
$h3 = fopen('ffabs://x', 'w');
fwrite($h3, 'x');
echo fflush($h3) ? '1' : '0', "\n";
fclose($h3);
stream_wrapper_unregister('ffabs');
--EXPECT--
1
0
0
