<?php
declare(strict_types=1);
foreach ([
    fn() => mb_ereg_replace(null, "b", "c"),
    fn() => mb_decode_mimeheader(null),
] as $i => $fn) {
    try {
        var_export($fn());
        echo " (#$i)\n";
    } catch (Throwable $e) {
        echo get_class($e), ": ", $e->getMessage(), " (#$i)\n";
    }
}
