<?php

declare(strict_types=1);

// Issue #11944 — scandir(null) coerces to "" then ValueError (ext/standard/dir.c).
try {
    scandir(null);
    echo "unexpected_success\n";
} catch (ValueError $e) {
    echo "ok\n";
} catch (TypeError) {
    echo "fail: TypeError\n";
    exit(1);
}
