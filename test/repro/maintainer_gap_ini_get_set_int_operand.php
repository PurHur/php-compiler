<?php
// Issue #17268: ini_get()/ini_set() int $option must TypeError (ext/standard/ini.c Z_PARAM_STR).

function probe(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, '=fail:uncaught', "\n";
    } catch (TypeError $e) {
        echo $label, '=TypeError', "\n";
    }
}

probe('ini_get', static fn () => ini_get(123));
probe('ini_set', static fn () => ini_set(456, 'x'));
