<?php
// #18591 — utf8_encode/utf8_decode(null) must TypeError (ext/standard/basic_functions.c)
foreach (['utf8_encode', 'utf8_decode'] as $fn) {
    try {
        $fn(null);
        echo "$fn: no_ex\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
