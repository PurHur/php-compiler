<?php
foreach ([
    'str_replace' => static fn () => str_replace(null, 'b', 'hay'),
    'str_ireplace' => static fn () => str_ireplace(null, 'b', 'Hay'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
