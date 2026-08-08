<?php
// Issue #28938 — wordwrap(..., break: …) named skip must not crash (sparse calledArgs).
try {
    echo wordwrap('aa bb', break: '-'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo wordwrap(string: 'aa bb', break: '-'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo wordwrap('aa bb', cut_long_words: false), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo wordwrap('aa bb', width: 2), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
