<?php

declare(strict_types=1);

// #16175 — gzeof() on fresh gz handle must return bool false (ext/zlib/zlib.c)
$fp = gzopen('php://temp', 'rb');
if (false === $fp) {
    echo "fail: gzopen\n";
    exit(1);
}
if (false !== gzeof($fp)) {
    echo 'fail: fresh gzeof got ', var_export(gzeof($fp), true), " expected false\n";
    exit(1);
}
gzclose($fp);
echo "ok\n";
