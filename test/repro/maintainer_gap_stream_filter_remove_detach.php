<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
rewind($fp);
$filter = stream_filter_append($fp, 'string.toupper', STREAM_FILTER_READ);

$fgetc = fgetc($fp);
$bulk = stream_get_contents($fp);

stream_filter_remove($filter);
rewind($fp);
$raw = stream_get_contents($fp);

fclose($fp);

$ok = 'H' === $fgetc
    && 'ELLO' === $bulk
    && 'hello' === $raw;

if (!$ok) {
    echo 'fail fgetc=', var_export($fgetc, true),
        ' bulk=', var_export($bulk, true),
        ' raw=', var_export($raw, true), "\n";
    exit(1);
}

echo "ok\n";
