<?php
// AOT compile+run #29352 — soft-null $step → ValueError (DEP skipped on user-script AOT fold)
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    range(0, 2, null);
    echo "no_ex\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
