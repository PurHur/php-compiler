<?php
/**
 * Repro for #26942 — AOT net_get_interfaces() must match Zend/VM/JIT (no segfault / garbage count).
 *
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces)
 */
$i = net_get_interfaces();
if (!is_array($i)) {
    echo "bad\n";
    exit(1);
}
echo count($i), "\n";
$hasUnicast = false;
foreach ($i as $info) {
    if (is_array($info) && isset($info['unicast']) && is_array($info['unicast']) && count($info['unicast']) > 0) {
        $hasUnicast = true;
        break;
    }
}
echo $hasUnicast ? "unicast-yes\n" : "unicast-no\n";
