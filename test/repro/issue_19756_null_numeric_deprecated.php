<?php
error_reporting(E_ALL);
foreach ([
    'number_format' => fn() => number_format(null),
    'chr' => fn() => chr(null),
    'dechex' => fn() => dechex(null),
] as $name => $fn) {
    $warns = [];
    set_error_handler(function ($no, $str) use (&$warns) {
        $warns[] = "$no:$str";
        return true;
    });
    $r = $fn();
    echo $name, ' r=', var_export($r, true), ' warns=', count($warns), "\n";
    if ($warns) {
        echo '  ', $warns[0], "\n";
    }
    restore_error_handler();
}
