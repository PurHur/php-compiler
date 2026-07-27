<?php
/**
 * Repro for #23715 — net_get_interfaces() unicast must be non-empty with family.
 * php-src: ext/standard/net.c
 */
$ifaces = net_get_interfaces();
if (!is_array($ifaces) || $ifaces === []) {
    echo "no_ifaces\n";
    exit(0);
}
$picked = null;
$name = null;
foreach (['lo', 'eth0'] as $prefer) {
    if (isset($ifaces[$prefer])) {
        $picked = $ifaces[$prefer];
        $name = $prefer;
        break;
    }
}
if (null === $picked) {
    foreach ($ifaces as $n => $info) {
        $name = $n;
        $picked = $info;
        break;
    }
}
$unicast = $picked['unicast'] ?? null;
$count = is_array($unicast) ? count($unicast) : -1;
echo 'iface=', $name, "\n";
echo 'unicast_count=', $count, "\n";
$family0 = is_array($unicast) && isset($unicast[0]['family']) ? (int) $unicast[0]['family'] : -1;
echo 'family0=', $family0, "\n";
$hasFamily = false;
if (is_array($unicast)) {
    foreach ($unicast as $u) {
        if (isset($u['family'])) {
            $hasFamily = true;
            break;
        }
    }
}
echo $hasFamily ? "has_family\n" : "no_family\n";
$hasLoAddr = false;
if (isset($ifaces['lo']['unicast']) && is_array($ifaces['lo']['unicast'])) {
    foreach ($ifaces['lo']['unicast'] as $u) {
        if (($u['address'] ?? '') === '127.0.0.1') {
            $hasLoAddr = true;
            break;
        }
    }
}
echo $hasLoAddr ? "lo_addr\n" : "no_lo_addr\n";
