--TEST--
stdlib ftruncate() on user wrappers dispatches stream_truncate (userspace.c, #25994)
--FILE--
<?php
class TruncWrap {
    public $context;
    private string $data = '';
    private int $pos = 0;
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        return true;
    }
    public function stream_write($data) {
        $this->data .= $data;
        return strlen($data);
    }
    public function stream_read($count) {
        $chunk = substr($this->data, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
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
        }
        return true;
    }
    public function stream_truncate($new_size) {
        $this->data = substr($this->data, 0, $new_size);
        if ($this->pos > $new_size) {
            $this->pos = $new_size;
        }
        return true;
    }
    public function stream_stat() {
        return ['size' => strlen($this->data)];
    }
}
stream_wrapper_register('uwtrunc', TruncWrap::class);
$f = fopen('uwtrunc://x', 'w+');
fwrite($f, 'abcdef');
echo var_export(ftruncate($f, 2), true), '|';
rewind($f);
echo var_export(stream_get_contents($f), true), "\n";
fclose($f);
stream_wrapper_unregister('uwtrunc');
--EXPECT--
true|'ab'
