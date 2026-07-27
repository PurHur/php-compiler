--TEST--
get_resource_type named resource argument (VM, issue #23342)
--FILE--
<?php
$rf = new ReflectionFunction('get_resource_type');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
$path = sys_get_temp_dir() . '/phpc_named_grt_' . getmypid() . '.txt';
file_put_contents($path, "x\n");
$f = fopen($path, 'r');
echo get_resource_type(resource: $f), PHP_EOL;
fclose($f);
@unlink($path);
try {
    $dummy = fopen('php://memory', 'r');
    get_resource_type(res: $dummy);
    echo "res accepted\n";
    fclose($dummy);
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
resource
stream
Unknown named parameter $res
