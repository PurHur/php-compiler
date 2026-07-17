<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
foreach ([
    'implode' => static fn () => implode(null, ['a', 'b']),
    'join' => static fn () => join(null, ['a']),
    'token_get_all' => static fn () => token_get_all(null),
    'sscanf' => static fn () => sscanf(null, '%s'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
