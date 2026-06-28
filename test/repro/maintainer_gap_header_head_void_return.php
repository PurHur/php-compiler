<?php

declare(strict_types=1);

$r = header('X-Test: ok', replace: true);
if (null !== $r) {
    echo 'fail: header() return=', var_export($r, true), ' type=', get_debug_type($r), "\n";
    exit(1);
}

$r2 = header_remove();
if (null !== $r2) {
    echo 'fail: header_remove() return=', var_export($r2, true), ' type=', get_debug_type($r2), "\n";
    exit(1);
}

echo "ok\n";
