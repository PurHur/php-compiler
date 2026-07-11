<?php

declare(strict_types=1);

/**
 * Maintainer repro: stream_filter_prepend() on php://memory (#11094).
 *
 * php-src: ext/standard/streams.c — php_stream_filter_prepend().
 */

$fp = fopen('php://memory', 'w+');
if (false === $fp) {
    echo "fail: fopen\n";
    exit(1);
}

stream_filter_prepend($fp, 'string.toupper', STREAM_FILTER_WRITE);
fwrite($fp, 'hi');
rewind($fp);
$out = stream_get_contents($fp);
fclose($fp);

echo $out === 'HI' ? "ok\n" : "fail: got {$out}\n";
exit($out === 'HI' ? 0 : 1);
