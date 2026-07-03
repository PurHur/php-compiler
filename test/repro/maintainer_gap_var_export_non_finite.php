<?php

declare(strict_types=1);

/**
 * Maintainer gap: var_export() on NAN/INF (#15328, ext/standard/var.c).
 *
 * Zend: php test/repro/maintainer_gap_var_export_non_finite.php
 * VM:   php bin/vm.php test/repro/maintainer_gap_var_export_non_finite.php
 */

$fail = 0;

$nanExport = var_export(NAN, true);
if ('NAN' !== $nanExport) {
    fwrite(STDERR, "var_export(NAN) expected NAN, got {$nanExport}\n");
    ++$fail;
}

$infExport = var_export(INF, true);
if ('INF' !== $infExport) {
    fwrite(STDERR, "var_export(INF) expected INF, got {$infExport}\n");
    ++$fail;
}

$stringNanExport = var_export((string) NAN, true);
if ("'NAN'" !== $stringNanExport) {
    fwrite(STDERR, "var_export((string) NAN) expected 'NAN', got {$stringNanExport}\n");
    ++$fail;
}

exit($fail);
