<?php
/** Repro #19566 — implode(",", null) TypeError cites Argument #1 ($array). */
try {
    implode(",", null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    join(",", null);
    echo "uncaught join\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
