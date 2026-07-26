<?php
/**
 * Repro #23229 — IntlListFormatter on PROFILE=8.5 + host intl.
 *
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/maintainer_gap_intl_list_formatter_85.php
 */
declare(strict_types=1);

if (!extension_loaded('intl')) {
    fwrite(STDERR, "skip: extension_loaded('intl') is false\n");
    exit(0);
}

echo 'class=', class_exists('IntlListFormatter') ? '1' : '0', "\n";
if (!class_exists('IntlListFormatter')) {
    fwrite(STDERR, "FAIL: IntlListFormatter missing\n");
    exit(1);
}

$f = new IntlListFormatter('en_US');
$out = $f->format(['A', 'B', 'C']);
echo 'format=', $out, "\n";
if ('A, B, and C' !== $out) {
    fwrite(STDERR, "FAIL: expected 'A, B, and C', got ".var_export($out, true)."\n");
    exit(1);
}

$f2 = new IntlListFormatter('en_US', IntlListFormatter::TYPE_AND, IntlListFormatter::WIDTH_WIDE);
echo 'format2=', $f2->format(['A', 'B', 'C']), "\n";

echo "ok\n";
