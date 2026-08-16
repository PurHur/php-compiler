--TEST--
vsprintf/vprintf Reflection values + named values: (#23333, basic_functions.stub.php)
--FILE--
<?php
foreach (['vsprintf', 'vprintf'] as $fn) {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
echo vsprintf(format: '%s-%s', values: ['a', 'b']), "\n";
ob_start();
vprintf(format: '%s', values: ['ok']);
echo ob_get_clean(), "\n";
try {
    vsprintf(format: '%s', args: ['x']);
    echo "args accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
vsprintf:format,values
vprintf:format,values
a-b
ok
Unknown named parameter $args
