<?php
// #18598 — trim/ltrim/rtrim/chop(null) must TypeError (ext/standard/string.c)
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        $fn(null);
        echo "$fn: no_ex\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
