<?php

/**
 * #29755 AOT probe — empty $pad_string ValueError (no set_error_handler closure).
 * Soft-null uses the same empty-pad path after DEP; empty string is AOT-safe.
 */
try {
    str_pad('x', 5, '');
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
