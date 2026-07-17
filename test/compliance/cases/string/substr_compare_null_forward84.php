<?php
foreach ([
    'haystack' => static fn () => substr_compare(null, 'a', 0),
    'needle' => static fn () => substr_compare('abc', null, 0),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
