<?php

declare(strict_types=1);

// Repro #30123 — fnmatch(null, …) under strict_types must TypeError (php-src fnmatch.c).
error_reporting(E_ALL);
try {
    var_export(fnmatch(null, 'a'));
    echo " bad:pattern:uncaught\n";
} catch (TypeError $e) {
    echo 'ok:pattern:'.$e->getMessage()."\n";
}

try {
    var_export(fnmatch('a', null));
    echo " bad:filename:uncaught\n";
} catch (TypeError $e) {
    echo 'ok:filename:'.$e->getMessage()."\n";
}
