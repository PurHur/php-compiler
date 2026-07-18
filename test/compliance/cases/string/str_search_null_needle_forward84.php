<?php
// Guard #20176 — null needle TypeError under PHP_COMPILER_PROFILE=8.4
foreach ([
    'strstr' => static fn () => strstr('abc', null),
    'stristr' => static fn () => stristr('Abc', null),
    'strpos' => static fn () => strpos('abc', null),
    'strrpos' => static fn () => strrpos('abc', null),
    'stripos' => static fn () => stripos('abc', null),
    'strripos' => static fn () => strripos('abc', null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
