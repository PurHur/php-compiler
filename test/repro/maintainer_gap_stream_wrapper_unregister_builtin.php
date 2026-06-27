<?php

declare(strict_types=1);

/** Issue #12620 — stream_wrapper_unregister() removes built-in schemes like Zend. */

if (!stream_wrapper_unregister('http')) {
    fwrite(STDERR, "fail: stream_wrapper_unregister('http') returned false — Zend returns true\n");
    exit(1);
}

$wrappers = stream_get_wrappers();
if (!\is_array($wrappers) || \in_array('http', $wrappers, true)) {
    fwrite(STDERR, "fail: http still listed after unregister\n");
    exit(1);
}

if (!stream_wrapper_restore('http')) {
    fwrite(STDERR, "fail: stream_wrapper_restore('http') returned false after unregister\n");
    exit(1);
}

$wrappers = stream_get_wrappers();
if (!\is_array($wrappers) || !\in_array('http', $wrappers, true)) {
    fwrite(STDERR, "fail: http missing after restore\n");
    exit(1);
}

echo "ok\n";
