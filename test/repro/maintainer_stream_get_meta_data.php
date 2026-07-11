<?php

declare(strict_types=1);

$f = fopen('php://memory', 'w+b');
$meta = stream_get_meta_data($f);
var_dump(is_array($meta));
if (is_array($meta)) {
    echo 'uri=' . ($meta['uri'] ?? '?') . "\n";
    echo 'mode=' . ($meta['mode'] ?? '?') . "\n";
    echo 'wrapper=' . ($meta['wrapper_type'] ?? '?') . "\n";
    echo 'seekable=' . var_export($meta['seekable'] ?? null, true) . "\n";
}
fclose($f);
