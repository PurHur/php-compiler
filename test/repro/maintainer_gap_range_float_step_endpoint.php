<?php

declare(strict_types=1);

/**
 * Maintainer gap: range() float step cumulative error (#15326, ext/standard/array.c).
 *
 * Zend: php test/repro/maintainer_gap_range_float_step_endpoint.php
 * VM:   php bin/vm.php test/repro/maintainer_gap_range_float_step_endpoint.php
 */

$fail = 0;
$r = range(1.0, 2.0, 0.1);
$count = count($r);
if (11 !== $count) {
    fwrite(STDERR, "count(range(1.0, 2.0, 0.1)) expected 11, got {$count}\n");
    ++$fail;
}
$last = $r[$count - 1];
if (2.0 !== $last) {
    fwrite(STDERR, "last element expected 2.0, got {$last}\n");
    ++$fail;
}

exit($fail);
