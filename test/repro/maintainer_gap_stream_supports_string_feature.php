<?php

declare(strict_types=1);

// Issue #16329 — stream_supports() string $feature under PHP_COMPILER_PROFILE=8.4
$fp = tmpfile();
if (false === $fp) {
    echo "tmpfile-fail\n";
    exit(1);
}

echo stream_supports($fp, 'seek') ? 'seek-yes' : 'seek-no', "\n";
echo stream_supports($fp, 'read') ? 'read-yes' : 'read-no', "\n";
echo stream_supports($fp, STREAM_SUPPORT_SEEK) ? 'const-seek-yes' : 'const-seek-no', "\n";

fclose($fp);
