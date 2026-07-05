<?php

declare(strict_types=1);

$fp = fopen('php://temp', 'r+');
if (false === $fp) {
    echo "fail\n";
    exit(1);
}
$meta = stream_get_meta_data($fp);
$type = $meta['stream_type'] ?? '';
fclose($fp);
echo 'TEMP' === $type ? "ok\n" : "fail:{$type}\n";
