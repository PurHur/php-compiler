<?php

error_reporting(E_ALL & ~E_DEPRECATED);
foreach ([
    'implode(",", null)',
    'join(",", null)',
    'implode(",", ["a","b"])',
] as $c) {
    echo $c, ' => ';
    try {
        var_export(eval('return '.$c.';'));
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage();
    }
    echo "\n";
}
