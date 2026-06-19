<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
fwrite($h, "hello\nworld\n");
fread($h, 3);

$meta = stream_get_meta_data($h);
var_export($meta['unread_bytes']);
echo "\n";
var_export($meta['seekable']);
echo "\n";
fclose($h);
