<?php

declare(strict_types=1);

// #18613 — file_get_contents data:// with offset/length must slice payload (ext/standard/file.c).
$payload = '0123456789';
$result = file_get_contents('data://text/plain,'.$payload, false, null, 3, 4);
if ('3456' !== $result) {
    fwrite(STDERR, "fail: expected 3456 got ".var_export($result, true)."\n");
    exit(1);
}
echo "ok\n";
