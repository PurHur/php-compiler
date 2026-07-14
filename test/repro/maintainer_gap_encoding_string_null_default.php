<?php
// #18932 — hebrev/uuencode/quoted_printable null TypeError on default profile.
foreach ([
    'hebrev' => static fn () => hebrev(null),
    'convert_uuencode' => static fn () => convert_uuencode(null),
    'quoted_printable_encode' => static fn () => quoted_printable_encode(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
