--TEST--
stdlib copy/file_put_contents/readfile on user wrappers (userspace.c, #26046)
--FILE--
<?php
class PathWrap {
    public $context;
    private $fp;
    private static $store = [];
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        $key = $path;
        if (!isset(self::$store[$key])) {
            self::$store[$key] = '';
        }
        $this->fp = fopen('php://temp', 'r+');
        if (false !== strpos($mode, 'r') || false !== strpos($mode, '+')) {
            fwrite($this->fp, self::$store[$key]);
            rewind($this->fp);
        }
        if (false !== strpos($mode, 'w') && false === strpos($mode, '+')) {
            self::$store[$key] = '';
        }
        $this->path = $key;
        $this->mode = $mode;
        return true;
    }
    public $path;
    public $mode;
    public function stream_eof() {
        return feof($this->fp);
    }
    public function stream_read($count) {
        return fread($this->fp, $count);
    }
    public function stream_write($data) {
        $n = fwrite($this->fp, $data);
        if (false !== $n && isset($this->path)) {
            $pos = ftell($this->fp);
            rewind($this->fp);
            self::$store[$this->path] = stream_get_contents($this->fp);
            fseek($this->fp, (int) $pos);
        }
        return $n;
    }
    public function stream_flush() {
        return true;
    }
    public function stream_close() {
        return true;
    }
}
stream_wrapper_register('uwpath', PathWrap::class);
file_put_contents('uwpath://a', 'data');
echo 'fput=', var_export(file_put_contents('uwpath://b', 'z'), true), "\n";
echo 'fget=', var_export(file_get_contents('uwpath://a'), true), "\n";
ob_start();
$n = readfile('uwpath://a');
$out = ob_get_clean();
echo 'readfile=', var_export($n, true), '|out=', var_export($out, true), "\n";
$dest = sys_get_temp_dir() . '/phpc_uw_copy_' . getmypid();
echo 'copy=', var_export(@copy('uwpath://a', $dest), true), "\n";
if (is_file($dest)) {
    echo 'copied=', var_export(file_get_contents($dest), true), "\n";
    unlink($dest);
} else {
    echo "copied=missing\n";
}
stream_wrapper_unregister('uwpath');
--EXPECT--
fput=1
fget='data'
readfile=4|out='data'
copy=true
copied='data'
