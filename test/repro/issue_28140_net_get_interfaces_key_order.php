<?php

declare(strict_types=1);

// Repro #28140 — net_get_interfaces() key order matches php-src net.c / getifaddrs.

$i = net_get_interfaces();
if (!is_array($i) || !isset($i['lo'])) {
    fwrite(STDERR, "fail: no lo\n");
    exit(1);
}

$keys = array_keys($i['lo']);
if (['unicast', 'up'] !== $keys) {
    fwrite(STDERR, 'fail: lo keys '.json_encode($keys)."\n");
    exit(1);
}

$names = array_keys($i);
$loPos = array_search('lo', $names, true);
if (false === $loPos) {
    fwrite(STDERR, "fail: lo missing\n");
    exit(1);
}
foreach ($names as $idx => $name) {
    if ('lo' === $name) {
        continue;
    }
    if ($idx < $loPos) {
        fwrite(STDERR, 'fail: '.$name." before lo\n");
        exit(1);
    }
}

echo 'keys=', json_encode($keys), "\n";
echo 'ifaces=', implode(',', $names), "\n";
echo "ok\n";
