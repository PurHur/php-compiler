--TEST--
stdlib fwrite() on user stream wrappers (#25972)
--FILE--
<?php
class FwUserWrap {
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
    public function stream_write($data) {
        $this->data .= $data;
        $this->pos = strlen($this->data);
        return strlen($data);
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
@stream_wrapper_unregister('fwuser');
stream_wrapper_register('fwuser', FwUserWrap::class);
$h = fopen('fwuser://x', 'r+');
echo fwrite($h, 'ZZ'), "\n";
echo ftell($h), "\n";
rewind($h);
echo stream_get_contents($h), "\n";
fclose($h);
stream_wrapper_unregister('fwuser');
--EXPECT--
2
2
ABCDEFGHZZ
