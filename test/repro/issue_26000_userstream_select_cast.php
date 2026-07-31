<?php
/**
 * Repro #26000 — stream_select on user wrappers must call stream_cast (userspace.c).
 */
class W {
    public $context;
    private $fp;
    public function stream_open($p, $m, $o, &$u) {
        $this->fp = fopen('php://temp', 'r+');
        fwrite($this->fp, 'x');
        rewind($this->fp);

        return true;
    }
    public function stream_cast($cast_as) {
        echo "cast=$cast_as|";

        return $this->fp;
    }
    public function stream_eof() {
        return feof($this->fp);
    }
    public function stream_read($c) {
        return fread($this->fp, $c);
    }
    public function stream_write($d) {
        return fwrite($this->fp, $d);
    }
}
stream_wrapper_register('uw', 'W');
$f = fopen('uw://x', 'r+');
$r = [$f];
$w = null;
$e = null;
$n = stream_select($r, $w, $e, 0);
echo 'n=' . var_export($n, true) . '|count=' . count($r) . "\n";
