<?php
// Guard #19276 — strlen/case/rev null TypeError under PHP_COMPILER_PROFILE=8.4
foreach ([
    'strlen' => static fn () => strlen(null),
    'strtolower' => static fn () => strtolower(null),
    'strtoupper' => static fn () => strtoupper(null),
    'strrev' => static fn () => strrev(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
