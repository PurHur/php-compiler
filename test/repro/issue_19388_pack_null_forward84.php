<?php
/** Repro #19388 — pack() null value operands TypeError on PHP_COMPILER_PROFILE=8.4. */
try {
    echo var_export(pack('a*', null), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo var_export(pack('H*', null), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo var_export(pack('c', null), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo ord(pack('c', 65)), "\n";
