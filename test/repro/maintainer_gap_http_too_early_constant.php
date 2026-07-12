<?php

declare(strict_types=1);

if (!defined('HTTP_TOO_EARLY')) {
    fwrite(STDERR, "FAIL: HTTP_TOO_EARLY undefined\n");
    exit(1);
}
if (HTTP_TOO_EARLY !== 425) {
    fwrite(STDERR, 'FAIL: HTTP_TOO_EARLY='.var_export(HTTP_TOO_EARLY, true)."\n");
    exit(1);
}
echo "ok\n";
