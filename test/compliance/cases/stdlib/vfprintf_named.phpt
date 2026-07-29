--TEST--
vfprintf Reflection stream/format/values + named call (issue #24535)
--FILE--
<?php
$rf = new ReflectionFunction('vfprintf');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$fp = fopen('php://memory', 'w+');
try {
    vfprintf(stream: $fp, format: '%s', values: ['hi']);
    echo "named_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
rewind($fp);
echo stream_get_contents($fp), "\n";
fclose($fp);
try {
    $fp2 = fopen('php://memory', 'w+');
    vfprintf(stream: $fp2, format: '%s', args: ['hi']);
    echo "args accepted\n";
    fclose($fp2);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
stream,format,values
named_ok
hi
Unknown named parameter $args
