--TEST--
get_resource_id Reflection resource param + named resource: (issue #24489, php-src basic_functions.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('get_resource_id');
echo $rf->getNumberOfRequiredParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
$fp = fopen('php://memory', 'r');
echo get_resource_id($fp) === get_resource_id(resource: $fp) ? 'named_ok' : 'named_mismatch', "\n";
fclose($fp);
try {
    $fp = fopen('php://memory', 'r');
    get_resource_id(res: $fp);
    echo "res accepted\n";
    fclose($fp);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
1
resource
named_ok
Unknown named parameter $res
