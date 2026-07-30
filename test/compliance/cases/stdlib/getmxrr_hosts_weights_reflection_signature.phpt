--TEST--
getmxrr/dns_get_mx Reflection hosts/weights match php-src stub (#23353, basic_functions.stub.php)
--FILE--
<?php
foreach (['getmxrr', 'dns_get_mx'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo "== $fn ==\n";
    foreach ($r->getParameters() as $p) {
        echo $p->getName(),
            ' byref=', (int) $p->isPassedByReference(),
            ' opt=', (int) $p->isOptional(),
            ' defAvail=', (int) $p->isDefaultValueAvailable();
        if ($p->isDefaultValueAvailable()) {
            echo ' ', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
$h = $w = [];
$ok = @getmxrr(hostname: 'localhost', hosts: $h, weights: $w);
echo 'named=', var_export(is_bool($ok), true), ' hosts=', var_export(is_array($h), true), "\n";
?>
--EXPECT--
== getmxrr ==
hostname byref=0 opt=0 defAvail=0
hosts byref=1 opt=0 defAvail=0
weights byref=1 opt=1 defAvail=1 NULL
== dns_get_mx ==
hostname byref=0 opt=0 defAvail=0
hosts byref=1 opt=0 defAvail=0
weights byref=1 opt=1 defAvail=1 NULL
named=true hosts=true
