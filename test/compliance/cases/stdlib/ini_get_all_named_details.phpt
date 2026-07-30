--TEST--
stdlib ini_get_all() named details with omitted extension (#25276, ext/standard/basic_functions.stub.php)
--FILE--
<?php
declare(strict_types=1);

$rf = new ReflectionFunction('ini_get_all');
$parts = [];
foreach ($rf->getParameters() as $p) {
    $parts[] = $p->getName()
        .':opt='.($p->isOptional() ? '1' : '0')
        .':'.($p->hasType() ? (string) $p->getType() : '-')
        .':'.($p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-');
}
echo 'params=', implode(',', $parts), "\n";

$r = ini_get_all(details: true);
echo isset($r['memory_limit']['global_value']) ? "named_details_ok\n" : "named_details_bad\n";

$flat = ini_get_all(extension: null, details: false);
echo is_string($flat['memory_limit'] ?? null) ? "named_flat_ok\n" : "named_flat_bad\n";
--EXPECT--
params=extension:opt=1:?string:null,details:opt=1:bool:true
named_details_ok
named_flat_ok
