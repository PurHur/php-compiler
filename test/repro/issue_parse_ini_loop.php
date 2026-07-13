<?php

foreach (['on', 'off'] as $v) {
    $ini = "flag = $v";
    $parsed = parse_ini_string($ini);
    echo "parse_ini_$v:";
    var_export($parsed['flag'] ?? null);
    echo "\n";
}
echo "done\n";
