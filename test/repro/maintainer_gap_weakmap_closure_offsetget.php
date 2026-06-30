<?php

declare(strict_types=1);

/** @return int */
$read = (function (): int {
    $wm = new WeakMap();
    $o = new stdClass();
    $wm[$o] = 9;

    return $wm[$o];
})();

if (9 !== $read) {
    fwrite(STDERR, 'read='.var_export($read, true)." expected 9\n");
    exit(1);
}
echo "ok\n";
