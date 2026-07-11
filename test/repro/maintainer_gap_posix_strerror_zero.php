<?php

declare(strict_types=1);

$msg = posix_strerror(0);
if ('Success' !== $msg) {
    fwrite(STDERR, 'fail: '.var_export($msg, true)."\n");
    exit(1);
}
echo "ok\n";
