<?php
foreach (['md5', 'sha1'] as $fn) {
    try {
        $r = $fn(null);
        echo "$fn: uncaught digest=".substr((string)$r, 0, 8)."\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
echo "ok\n";
