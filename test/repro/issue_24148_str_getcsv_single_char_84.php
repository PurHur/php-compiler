<?php
// #24148 — str_getcsv() multi-byte separator/enclosure/escape under PROFILE=8.4
foreach ([
    'sep2' => static fn () => str_getcsv('a,b', ',,', '"', '"'),
    'enc2' => static fn () => str_getcsv('a,b', ',', '""', '"'),
    'esc2' => static fn () => str_getcsv('a,b', ',', '"', 'xx'),
    'esc_empty_ok' => static fn () => str_getcsv('a,b', ',', '"', ''),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' OK ', json_encode($r), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
