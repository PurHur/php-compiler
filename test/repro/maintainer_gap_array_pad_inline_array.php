<?php

/**
 * Issue #9549 — inline array literal + numeric-string length vs Zend/php-src.
 *
 *   php test/repro/maintainer_gap_array_pad_inline_array.php
 *   php bin/vm.php test/repro/maintainer_gap_array_pad_inline_array.php
 */

var_export(array_pad([1, 2], 5, 'x'));
echo "\n";

var_export(array_pad([1, 2], 5.7, 'x'));
echo "\n";

(function (): void {
    $a = [1, 2];
    var_export(array_pad($a, '5', 'x'));
    echo "\n";
})();
