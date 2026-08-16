<?php
declare(strict_types=1);
/** array_* preserve_keys null under strict_types → TypeError (#31442). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach ([
    'array_slice' => static fn () => array_slice([1, 2, 3], 0, 1, null),
    'array_chunk' => static fn () => array_chunk([1, 2], 1, null),
    'array_reverse' => static fn () => array_reverse([1, 2], null),
] as $name => $fn) {
    try {
        $fn();
        echo "$name: fail\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
