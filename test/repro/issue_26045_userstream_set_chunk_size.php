<?php
/**
 * Repro #26045 — stream_set_chunk_size on user wrappers returns previous size.
 */
class W {
    public $context;
    private $fp;
    public function stream_open($p, $m, $o, &$u) {
        $this->fp = fopen('php://temp', 'r+');

        return true;
    }
    public function stream_eof() {
        return true;
    }
    public function stream_read($c) {
        return '';
    }
    public function stream_write($d) {
        return 0;
    }
}
stream_wrapper_register('uw', 'W');
$f = fopen('uw://x', 'r+');
echo var_export(stream_set_chunk_size($f, 16), true), "\n";
echo var_export(stream_set_chunk_size($f, 32), true), "\n";
