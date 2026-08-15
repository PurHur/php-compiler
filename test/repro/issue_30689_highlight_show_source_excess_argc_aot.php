<?php

/**
 * AOT-only repro: direct calls (no variable-function) — #30689.
 *
 * php-src: ext/standard/url_scanner_ex.re / basic_functions.stub.php
 *
 * AOT: php bin/compile.php -o /tmp/hf30689aot test/repro/issue_30689_highlight_show_source_excess_argc_aot.php && /tmp/hf30689aot
 */
try {
    highlight_file('php://memory', false, 1);
    echo "highlight_file:NO_THROW\n";
} catch (Throwable $e) {
    echo 'highlight_file:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    show_source('php://memory', false, 1);
    echo "show_source:NO_THROW\n";
} catch (Throwable $e) {
    echo 'show_source:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    highlight_file();
    echo "hf_lo:NO_THROW\n";
} catch (Throwable $e) {
    echo 'hf_lo:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "done\n";
