<?php
/**
 * Issue #23353 — getmxrr / dns_get_mx Reflection hosts/weights (php-src basic_functions.stub.php).
 */
foreach (['getmxrr', 'dns_get_mx'] as $fn) {
    $n = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $s = $p->getName();
        if ($p->isPassedByReference()) {
            $s = '&'.$s;
        }
        if ($p->isOptional()) {
            $s .= '=';
        }
        if ($p->isDefaultValueAvailable()) {
            $s .= var_export($p->getDefaultValue(), true);
        }
        $n[] = $s;
    }
    echo $fn, ': ', implode(',', $n), "\n";
}

$h = $w = [];
try {
    $ok = @getmxrr(hostname: 'localhost', hosts: $h, weights: $w);
    echo 'named ok=', var_export($ok, true), ' hosts_is_array=', var_export(is_array($h), true), "\n";
} catch (Throwable $e) {
    echo 'named: ', $e->getMessage(), "\n";
}
