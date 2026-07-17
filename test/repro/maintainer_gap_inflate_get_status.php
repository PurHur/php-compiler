<?php
/** Repro for #20008 — inflate_get_status / inflate_get_read_len after inflate_add. */
$raw = gzencode('hello');
$ctx = inflate_init(ZLIB_ENCODING_GZIP);
$out = inflate_add($ctx, $raw, ZLIB_FINISH);
echo 'out=', var_export($out, true), "\n";
echo 'status=', inflate_get_status($ctx), "\n";
echo 'read_len=', inflate_get_read_len($ctx), "\n";
