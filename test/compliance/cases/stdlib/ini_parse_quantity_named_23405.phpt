--TEST--
stdlib ini_parse_quantity Reflection/named param shorthand (#23405, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('ini_parse_quantity');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
    $t = $p->getType();
    echo $p->getName(), ' type=', $t instanceof ReflectionNamedType ? $t->getName() : '(none)', "\n";
}
$rt = $r->getReturnType();
echo 'return=', $rt instanceof ReflectionNamedType ? $rt->getName() : '(none)', "\n";
echo 'pos=', ini_parse_quantity('10k'), "\n";
echo 'named=', ini_parse_quantity(shorthand: '10k'), "\n";
try {
    ini_parse_quantity(value: '10k');
    echo "legacy value ok\n";
} catch (Throwable $e) {
    echo 'legacy value ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
shorthand type=string
return=int
pos=10240
named=10240
legacy value ERR=Error: Unknown named parameter $value
