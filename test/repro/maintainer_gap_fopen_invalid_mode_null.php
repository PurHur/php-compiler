<?php

declare(strict_types=1);

$h = @fopen('php://memory', 'invalid');
if (false === $h) {
    fwrite(STDERR, "fail: returned boolean false\n");
    exit(1);
}
if ('resource' !== gettype($h)) {
    fwrite(STDERR, 'fail: gettype='.gettype($h)."\n");
    exit(1);
}
if ('NULL' !== var_export($h, true)) {
    fwrite(STDERR, 'fail: var_export='.var_export($h, true)."\n");
    exit(1);
}
if (!is_resource($h)) {
    fwrite(STDERR, "fail: is_resource false\n");
    exit(1);
}
echo "ok\n";
