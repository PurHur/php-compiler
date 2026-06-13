<?php

declare(strict_types=1);

/**
 * Issue #3568 repro — preg_replace_callback_array() parity with php-src.
 */
$out = preg_replace_callback_array(
    ['/\d+/' => fn(array $m): string => '['.$m[0].']'],
    'a1b2'
);
echo $out, "\n";
