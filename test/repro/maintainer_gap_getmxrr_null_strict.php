<?php
declare(strict_types=1);
$hosts = [];
try {
    var_export(getmxrr(null, $hosts));
    echo "\nbad:getmxrr(null):coerce\n";
} catch (Throwable $e) {
    echo 'ok:getmxrr(null):', get_class($e), "\n";
}
$hosts2 = [];
try {
    var_export(dns_get_mx(null, $hosts2));
    echo "\nbad:dns_get_mx(null):coerce\n";
} catch (Throwable $e) {
    echo 'ok:dns_get_mx(null):', get_class($e), "\n";
}
