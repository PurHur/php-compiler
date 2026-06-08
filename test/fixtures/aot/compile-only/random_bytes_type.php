<?php
// Compile-only (#4626): random_bytes() Z_PARAM_LONG numeric-string + array TypeError lowering.
echo strlen(random_bytes('16')), "\n";
try {
    random_bytes([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
