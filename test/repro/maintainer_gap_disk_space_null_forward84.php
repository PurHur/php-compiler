<?php
// #18994 — disk_*_space(null) TypeError on PHP_COMPILER_PROFILE=8.4.
foreach ([
    'disk_free_space' => static fn () => disk_free_space(null),
    'disk_total_space' => static fn () => disk_total_space(null),
    'diskfreespace' => static fn () => diskfreespace(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
        exit(1);
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
