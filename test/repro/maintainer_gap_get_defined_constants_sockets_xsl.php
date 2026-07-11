<?php

declare(strict_types=1);

$c = get_defined_constants(true);
$missing = [];
foreach (['sockets', 'xsl'] as $module) {
    if (!isset($c[$module]) || 0 === count($c[$module])) {
        $missing[] = $module;
    }
}
if ([] !== $missing) {
    echo 'FAIL: missing buckets: ', implode(',', $missing), "\n";
    exit(1);
}
echo 'sockets=', count($c['sockets']), ' xsl=', count($c['xsl']), "\n";
echo "ok\n";
