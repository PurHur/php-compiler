--TEST--
stdlib ftell()/fseek()/rewind() on user stream wrappers (#25971)
--FILE--
<?php
class SeekUserWrap {
    public $pos = 0;
    public $data = 'ABCDEFGH';
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_read($count) {
        $r = substr($this->data, $this->pos, $count);
        $this->pos += strlen($r);
        return $r;
    }
    public function stream_eof() {
        return $this->pos >= strlen($this->data);
    }
    public function stream_tell() {
        return $this->pos;
    }
    public function stream_seek($offset, $whence) {
        if ($whence === SEEK_SET) {
            $this->pos = $offset;
        } elseif ($whence === SEEK_CUR) {
            $this->pos += $offset;
        } elseif ($whence === SEEK_END) {
            $this->pos = strlen($this->data) + $offset;
        } else {
            return false;
        }
        return $this->pos >= 0;
    }
    public function stream_stat() {
        return ['size' => strlen($this->data)];
    }
}
@stream_wrapper_unregister('seekuser');
stream_wrapper_register('seekuser', SeekUserWrap::class);
$h = fopen('seekuser://x', 'r');
echo fread($h, 2), "\n";
echo ftell($h), "\n";
echo fseek($h, 3), "\n";
echo ftell($h), "\n";
var_export(rewind($h));
echo "\n";
echo ftell($h), "\n";
echo fseek($h, -2, SEEK_END), "\n";
echo ftell($h), "\n";
fclose($h);
stream_wrapper_unregister('seekuser');
--EXPECT--
AB
2
0
3
true
0
0
6
