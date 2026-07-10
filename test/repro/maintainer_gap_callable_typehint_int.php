<?php
/**
 * Issue #17742 — callable type hint must reject non-callable int at call boundary.
 */
function takesCallable(callable $c): void {
    echo 'entered', "\n";
}

try {
    takesCallable(1);
    echo 'no error', "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
