<?php

declare(strict_types=1);

/**
 * Repro: highlight_file/show_source excess argc → ArgumentCountError (#30689).
 *
 * php-src: ext/standard/url_scanner_ex.re / basic_functions.stub.php
 *
 * VM:  php bin/vm.php test/repro/issue_30689_highlight_show_source_excess_argc.php
 * JIT: php bin/jit.php test/repro/issue_30689_highlight_show_source_excess_argc.php
 */
foreach (['highlight_file', 'show_source'] as $fn) {
    try {
        $fn('php://memory', false, 1);
        echo "$fn:NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    highlight_file();
    echo "hf_lo:NO_THROW\n";
} catch (Throwable $e) {
    echo 'hf_lo:', get_class($e), ':', $e->getMessage(), "\n";
}
$html = highlight_file('php://memory', true);
echo 'ok:', (is_string($html)) ? '1' : '0', "\n";
