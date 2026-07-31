--TEST--
stdlib stream_select() on user wrappers dispatches stream_cast (userspace.c, #26000)
--FILE--
<?php
class SelectWrap {
    public $context;
    private $fp;
    public static $casts = [];
    public function stream_open($path, $mode, $options, &$opened_path = null) {
        $this->fp = fopen('php://temp', 'r+');
        fwrite($this->fp, 'x');
        rewind($this->fp);
        return true;
    }
    public function stream_cast($cast_as) {
        self::$casts[] = $cast_as;
        return $this->fp;
    }
    public function stream_eof() {
        return feof($this->fp);
    }
    public function stream_read($count) {
        return fread($this->fp, $count);
    }
    public function stream_write($data) {
        return fwrite($this->fp, $data);
    }
}
stream_wrapper_register('uwsel', SelectWrap::class);
$f = fopen('uwsel://x', 'r+');
$r = [$f];
$w = null;
$e = null;
$n = stream_select($r, $w, $e, 0);
echo 'casts=', implode(',', SelectWrap::$casts), "\n";
echo 'n=', var_export($n, true), '|count=', count($r), "\n";
fclose($f);
stream_wrapper_unregister('uwsel');

class NoCastWrap {
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
}
stream_wrapper_register('uwnocast', NoCastWrap::class);
$f2 = fopen('uwnocast://x', 'r');
$r2 = [$f2];
$w2 = null;
$e2 = null;
try {
    stream_select($r2, $w2, $e2, 0);
    echo "nocast=ok\n";
} catch (ValueError $ex) {
    echo 'nocast=', $ex->getMessage(), "\n";
}
fclose($f2);
stream_wrapper_unregister('uwnocast');
--EXPECT--
casts=3,3
n=1|count=1
nocast=No stream arrays were passed
