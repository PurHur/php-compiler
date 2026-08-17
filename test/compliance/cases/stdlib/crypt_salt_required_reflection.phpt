--TEST--
crypt Reflection $salt required (VM, issue #28920, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('crypt');
$p = $r->getParameters()[1];
echo 'salt_optional=', $p->isOptional() ? '1' : '0', "\n";
echo 'req=', $r->getNumberOfRequiredParameters(), "\n";
echo 'types=', (string) $r->getParameters()[0]->getType(), ' ', (string) $p->getType(), "\n";
try {
    crypt('x');
    echo "argc=ok\n";
} catch (Throwable $e) {
    echo 'argc=', get_class($e), "\n";
}
?>
--EXPECT--
salt_optional=0
req=2
types=string string
argc=ArgumentCountError
