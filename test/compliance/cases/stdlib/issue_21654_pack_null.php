<?php
try {
    echo bin2hex(pack("C", null)), "\n";
    echo bin2hex(pack("n", null)), "\n";
    echo strlen(pack("f", null)), "\n";
    echo bin2hex(pack("N", null)), "\n";
    echo "pack OK\n";
} catch (Throwable $e) {
    echo "pack " . get_class($e) . ": " . $e->getMessage() . "\n";
}
