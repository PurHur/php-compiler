<?php
// #18850 / #19318 — Z_PARAM_LONG / number_format / wordwrap / str_pad null must TypeError on 8.4 forward profile.
foreach ([
    'chr' => static fn () => chr(null),
    'wordwrap' => static fn () => wordwrap(null),
    'number_format' => static fn () => number_format(null),
    'dechex' => static fn () => dechex(null),
    'decbin' => static fn () => decbin(null),
    'decoct' => static fn () => decoct(null),
    'str_pad' => static fn () => str_pad(null, 5),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
        exit(1);
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
