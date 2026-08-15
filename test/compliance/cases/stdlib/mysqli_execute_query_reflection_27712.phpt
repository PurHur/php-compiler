--TEST--
ext/mysqli mysqli_execute_query Reflection stubs (#27712, mysqli.stub.php)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionFunction('mysqli_execute_query');
echo 'arity=', $r->getNumberOfParameters(), PHP_EOL;
echo 'required=', $r->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '-';
    if ($p->isDefaultValueAvailable()) {
        echo ' default=', var_export($p->getDefaultValue(), true);
    }
    echo PHP_EOL;
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;

$m = (new ReflectionClass('mysqli'))->getMethod('execute_query');
echo 'method_arity=', $m->getNumberOfParameters(), PHP_EOL;
echo 'method_required=', $m->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($m->getParameters() as $p) {
    echo 'm:', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '-';
    if ($p->isDefaultValueAvailable()) {
        echo ' default=', var_export($p->getDefaultValue(), true);
    }
    echo PHP_EOL;
}
echo 'method_return=', $m->hasReturnType() ? (string) $m->getReturnType() : '-', PHP_EOL;

try {
    mysqli_execute_query(mysql: null, query: 'SELECT 1');
    echo "named:ok\n";
} catch (TypeError $e) {
    echo 'named:TypeError:', str_contains($e->getMessage(), 'mysqli') ? 'mysqli' : $e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo 'named:', get_class($e), ':', $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
arity=3
required=2
mysql type=mysqli
query type=string
params type=?array default=NULL
return=mysqli_result|bool
method_arity=2
method_required=1
m:query type=string
m:params type=?array default=NULL
method_return=mysqli_result|bool
named:TypeError:mysqli
