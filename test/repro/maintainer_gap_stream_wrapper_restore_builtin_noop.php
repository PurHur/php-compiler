<?php

declare(strict_types=1);

/** Issue #12621 — stream_wrapper_restore() noop on unchanged built-in returns true + E_NOTICE. */

$ok = stream_wrapper_restore('http');
if (!$ok) {
    fwrite(STDERR, "fail: stream_wrapper_restore('http') returned false — Zend returns true (E_NOTICE)\n");
    exit(1);
}

$wrappers = stream_get_wrappers();
if (!\is_array($wrappers) || !\in_array('http', $wrappers, true)) {
    fwrite(STDERR, "fail: http missing from wrappers after noop restore\n");
    exit(1);
}

echo "ok\n";
