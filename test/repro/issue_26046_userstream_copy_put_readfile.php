<?php
/**
 * Repro #26046 — copy / file_put_contents / readfile on user wrappers.
 */
class W {
    public $context;
    private $fp;
    private static $store = [];
    public function stream_open($p, $m, $o, &$u) {
        if (!isset(self::$store[$p])) {
            self::$store[$p] = '';
        }
        $this->fp = fopen('php://temp', 'r+');
        $this->path = $p;
        if (false !== strpos($m, 'r') || false !== strpos($m, '+')) {
            fwrite($this->fp, self::$store[$p]);
            rewind($this->fp);
        }
        if (false !== strpos($m, 'w') && false === strpos($m, '+')) {
            self::$store[$p] = '';
        }

        return true;
    }
    public $path;
    public function stream_eof() {
        return feof($this->fp);
    }
    public function stream_read($c) {
        return fread($this->fp, $c);
    }
    public function stream_write($d) {
        $n = fwrite($this->fp, $d);
        if (false !== $n) {
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
}
stream_wrapper_register('uw', 'W');
echo 'fput=', var_export(file_put_contents('uw://y', 'z'), true), "\n";
file_put_contents('uw://x', 'data');
ob_start();
$n = readfile('uw://x');
$out = ob_get_clean();
echo 'readfile=', var_export($n, true), '|out=', var_export($out, true), "\n";
$dest = sys_get_temp_dir() . '/phpc_uw_copy_repro_' . getmypid();
echo 'copy=', var_export(@copy('uw://x', $dest), true), "\n";
if (is_file($dest)) {
    echo 'copied=', var_export(file_get_contents($dest), true), "\n";
    unlink($dest);
}
