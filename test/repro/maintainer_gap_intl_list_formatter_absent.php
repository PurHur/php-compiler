<?php
/**
 * Repro #23229 — IntlListFormatter withheld on PROFILE&lt;8.5 (php-src-strict).
 *
 *   PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/maintainer_gap_intl_list_formatter_absent.php
 */
declare(strict_types=1);

if (!extension_loaded('intl')) {
    echo "skip_no_intl\n";
    exit(0);
}

echo 'class=', class_exists('IntlListFormatter') ? '1' : '0', "\n";
if (class_exists('IntlListFormatter')) {
    fwrite(STDERR, "FAIL: IntlListFormatter must be withheld on PROFILE<8.5\n");
    exit(1);
}
echo "ok\n";
