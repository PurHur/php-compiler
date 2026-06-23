<?php

enum Ep: string
{
    case A = '/tmp/x';
}

$fns = [
    'dirname',
    'basename',
    'pathinfo',
    'realpath',
    'file_exists',
    'is_readable',
];

foreach ($fns as $fn) {
    try {
        $fn(Ep::A);
        echo "{$fn} ok\n";
    } catch (TypeError $e) {
        echo "{$fn} TypeError\n";
    }
}
