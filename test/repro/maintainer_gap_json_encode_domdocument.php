<?php

declare(strict_types=1);

$dom = new DOMDocument();
$dom->loadHTML('<p>hi</p>');
$encoded = json_encode($dom);
if ('{}' !== $encoded) {
    fwrite(STDERR, "expected {}, got ".var_export($encoded, true)."\n");
    fwrite(STDERR, 'json_last_error: '.json_last_error().' '.json_last_error_msg()."\n");
    exit(1);
}
echo "ok\n";
