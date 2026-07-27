--TEST--
stdlib net_get_interfaces() unicast non-empty with family (#23715, ext/standard/net.c)
--FILE--
<?php
$ifaces = net_get_interfaces();
if (!is_array($ifaces) || $ifaces === []) {
    echo "no_ifaces\n";
    exit(0);
}
$picked = null;
foreach (['lo', 'eth0'] as $prefer) {
    if (isset($ifaces[$prefer])) {
        $picked = $ifaces[$prefer];
        break;
    }
}
if (null === $picked) {
    foreach ($ifaces as $info) {
        $picked = $info;
        break;
    }
}
$unicast = $picked['unicast'] ?? null;
echo (is_array($unicast) && count($unicast) > 0) ? "unicast_nonempty\n" : "unicast_empty\n";
$hasFamily = false;
$hasPacketOrInet = false;
if (is_array($unicast)) {
    foreach ($unicast as $u) {
        if (isset($u['family'])) {
            $hasFamily = true;
            $fam = (int) $u['family'];
            if ($fam === 17 || $fam === 2 || $fam === 10) {
                $hasPacketOrInet = true;
            }
        }
    }
}
echo $hasFamily ? "has_family\n" : "no_family\n";
echo $hasPacketOrInet ? "known_family\n" : "unknown_family\n";
$found = false;
if (isset($ifaces['lo']['unicast']) && is_array($ifaces['lo']['unicast'])) {
    foreach ($ifaces['lo']['unicast'] as $u) {
        if (($u['address'] ?? '') === '127.0.0.1') {
            $found = true;
            break;
        }
    }
}
echo $found ? "lo_addr\n" : "no_lo_addr\n";
--EXPECT--
unicast_nonempty
has_family
known_family
lo_addr
