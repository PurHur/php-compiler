--TEST--
stdlib net_get_interfaces() JIT — loopback interface present (#6106)
--FILE--
<?php
$ifaces = net_get_interfaces();
if (!is_array($ifaces)) {
    echo "not_array\n";
    exit(0);
}
echo isset($ifaces['lo']) ? "has_lo\n" : "no_lo\n";
if (!isset($ifaces['lo'])) {
    exit(0);
}
$lo = $ifaces['lo'];
echo is_array($lo['unicast'] ?? null) ? "unicast_list\n" : "no_unicast\n";
echo is_bool($lo['up'] ?? null) ? "up_bool\n" : "no_up\n";
$found = false;
foreach ($lo['unicast'] as $u) {
    if (($u['address'] ?? '') === '127.0.0.1') {
        $found = true;
        break;
    }
}
echo $found ? "lo_addr\n" : "no_lo_addr\n";
--EXPECT--
has_lo
unicast_list
up_bool
lo_addr
