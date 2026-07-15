<?php
// Guard #19273 — null haystack TypeError under PHP_COMPILER_PROFILE=8.4
foreach ([
    'str_contains' => static fn () => str_contains(null, 'x'),
    'str_starts_with' => static fn () => str_starts_with(null, 'x'),
    'str_ends_with' => static fn () => str_ends_with(null, 'x'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
