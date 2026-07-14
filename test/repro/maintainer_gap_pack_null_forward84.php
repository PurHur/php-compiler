<?php
// #18992 — pack() null value operands TypeError on PHP_COMPILER_PROFILE=8.4.
foreach ([
    'pack H*' => static fn () => pack('H*', null),
    'pack c' => static fn () => pack('c', null),
    'pack a5' => static fn () => pack('a5', null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
        exit(1);
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
