<?php
/**
 * #35426 — AOT stream_filter_append string.toupper must transform reads.
 *
 * php-src: ext/standard/streamsfuncs.c — filter apply on stream_get_contents.
 */
$f = fopen('php://memory', 'r+');
fwrite($f, 'Hello');
rewind($f);
stream_filter_append($f, 'string.toupper');
echo stream_get_contents($f), "\n";

$g = fopen('php://temp', 'r+');
fwrite($g, 'abc');
rewind($g);
stream_filter_append($g, 'string.rot13');
echo stream_get_contents($g), "\n";
