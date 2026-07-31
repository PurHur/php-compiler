<?php
/**
 * Repro #25993 — stream_get_meta_data() on user wrappers → user-space|user-space
 * php-src: main/streams/userspace.c, ext/standard/streamsfuncs.c
 */
class W {
    public $context;
    public function stream_open($p, $m, $o, &$u = null) {
        return true;
    }
    public function stream_read($c) {
        return 'hi';
    }
    public function stream_eof() {
        return true;
    }
    public function stream_stat() {
        return [];
    }
}
stream_wrapper_register('uw', 'W');
$f = fopen('uw://x', 'r');
$m = stream_get_meta_data($f);
echo $m['wrapper_type'], '|', $m['stream_type'], "\n";
