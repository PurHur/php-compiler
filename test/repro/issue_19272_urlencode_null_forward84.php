<?php
// Repro #19272 — urlencode family null → TypeError under PHP_COMPILER_PROFILE=8.4
foreach (['urlencode', 'rawurlencode', 'urldecode', 'rawurldecode'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn.': TypeError'."\n";
    }
}
echo "ok\n";
