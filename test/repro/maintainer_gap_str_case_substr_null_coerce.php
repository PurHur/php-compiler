<?php
// #18599 — strtolower/strtoupper/substr(null) must TypeError (ext/standard/string.c)
foreach (['strtolower', 'strtoupper'] as $fn) {
    try {
        $fn(null);
        echo "$fn: no_ex\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
try {
    substr(null, 0);
    echo "substr: no_ex\n";
} catch (TypeError $e) {
    echo "substr: TypeError\n";
}
