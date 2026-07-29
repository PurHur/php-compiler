<?php
declare(strict_types=1);

/**
 * #24128 — GRAPHEME_EXTR_* must be UNDEF when ext/intl is not loaded (php-src-strict).
 *
 * ./script/docker-exec.sh -- bash -lc \
 *   'php test/repro/maintainer_gap_grapheme_extr_phantom_no_intl.php; php bin/vm.php test/repro/maintainer_gap_grapheme_extr_phantom_no_intl.php'
 */
echo 'intl=', extension_loaded('intl') ? 'yes' : 'no', "\n";
echo 'grapheme_extract=', function_exists('grapheme_extract') ? 'yes' : 'no', "\n";
foreach (['GRAPHEME_EXTR_COUNT', 'GRAPHEME_EXTR_MAXBYTES', 'GRAPHEME_EXTR_MAXCHARS'] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
