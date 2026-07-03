<?php

declare(strict_types=1);

/**
 * Maintainer gap: sscanf() %x/%X 64-bit overflow (#15327, ext/standard/formatted_io.c).
 *
 * Zend: php test/repro/maintainer_gap_sscanf_hex_overflow.php
 * VM:   php bin/vm.php test/repro/maintainer_gap_sscanf_hex_overflow.php
 */

$fail = 0;
$expected = 9223372036854775807;

$x = sscanf('FFFFFFFFFFFFFFFF', '%x');
if (!is_array($x) || ($x[0] ?? null) !== $expected) {
    fwrite(STDERR, 'sscanf %x overflow: expected '.(string) $expected.', got '.var_export($x[0] ?? null, true)."\n");
    ++$fail;
}

$X = sscanf('FFFFFFFFFFFFFFFF', '%X');
if (!is_array($X) || ($X[0] ?? null) !== $expected) {
    fwrite(STDERR, 'sscanf %X overflow: expected '.(string) $expected.', got '.var_export($X[0] ?? null, true)."\n");
    ++$fail;
}

exit($fail);
