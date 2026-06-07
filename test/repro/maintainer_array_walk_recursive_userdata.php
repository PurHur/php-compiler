<?php

declare(strict_types=1);

/**
 * Maintainer repro: array_walk_recursive() userdata third argument (#4913).
 *
 * Compare with Zend: php test/repro/maintainer_array_walk_recursive_userdata.php
 * VM: php bin/vm.php test/repro/maintainer_array_walk_recursive_userdata.php
 */

$seen = [];
$a = ['a' => [1]];
array_walk_recursive(
    $a,
    static function (&$v, $k, $userdata) use (&$seen) {
        $seen[] = $k . ':' . $userdata;
    },
    'tag'
);
var_export($seen);
echo "\n";
