<?php
// #28474 — zero-arg dump/encode builtins → ArgumentCountError (php-src-strict).
// Direct calls so AOT lowers each builtin argc guard.
try {
    json_decode();
    echo "json_decode:OK\n";
} catch (Throwable $e) {
    echo 'json_decode:', get_class($e), "\n";
}
try {
    serialize();
    echo "serialize:OK\n";
} catch (Throwable $e) {
    echo 'serialize:', get_class($e), "\n";
}
try {
    unserialize();
    echo "unserialize:OK\n";
} catch (Throwable $e) {
    echo 'unserialize:', get_class($e), "\n";
}
try {
    var_export();
    echo "var_export:OK\n";
} catch (Throwable $e) {
    echo 'var_export:', get_class($e), "\n";
}
try {
    print_r();
    echo "print_r:OK\n";
} catch (Throwable $e) {
    echo 'print_r:', get_class($e), "\n";
}
try {
    var_dump();
    echo "var_dump:OK\n";
} catch (Throwable $e) {
    echo 'var_dump:', get_class($e), "\n";
}
