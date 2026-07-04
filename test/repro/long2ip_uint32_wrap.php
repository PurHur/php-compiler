<?php
// Issue #9297 — long2ip() uint32 wrap (ext/standard/basic_functions.c).
$cases = [-1 => '255.255.255.255', 4294967296 => '0.0.0.0'];
foreach ($cases as $input => $expected) {
    $got = long2ip($input);
    echo "long2ip($input) => ";
    var_export($got);
    echo ' (expected ';
    var_export($expected);
    echo ")\n";
}
