<?php

declare(strict_types=1);

$pv = phpversion();
$cv = PHP_VERSION;
if ($pv !== $cv || 0 !== version_compare($pv, $cv)) {
    echo "FAIL phpversion()={$pv} PHP_VERSION={$cv}\n";
    exit(1);
}
echo "ok\n";
