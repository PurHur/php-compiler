<?php

declare(strict_types=1);

if (!defined('STREAM_META_SEEKABLE')) {
    echo "STREAM_META_SEEKABLE undefined\n";
    exit(1);
}

if (8 !== STREAM_META_SEEKABLE) {
    echo 'STREAM_META_SEEKABLE value=' . STREAM_META_SEEKABLE . " expected 8\n";
    exit(1);
}

$fp = fopen('php://memory', 'r+');
if (false === $fp) {
    echo "fopen failed\n";
    exit(1);
}

if (!stream_supports($fp, STREAM_META_SEEKABLE)) {
    echo "stream_supports seekable false for php://memory\n";
    exit(1);
}

fclose($fp);
echo "OK\n";
