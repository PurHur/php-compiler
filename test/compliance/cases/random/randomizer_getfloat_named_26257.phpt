--TEST--
Stdlib: Random\Randomizer getFloat/getBytesFromString named args + Reflection (#26257, ext/random/randomizer.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || version_compare(getenv('PHP_COMPILER_PROFILE'), '8.3', '<')) {
    die('skip requires PHP_COMPILER_PROFILE >= 8.3');
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach (['getFloat', 'getBytesFromString', 'nextFloat'] as $m) {
    $r = new ReflectionMethod(Random\Randomizer::class, $m);
    echo $m, ' arity=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters();
    echo ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    echo "\n";
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', $p->getName(),
            ' type=', $t ? (string) $t : 'none',
            ' optional=', (int) $p->isOptional(), "\n";
    }
}

$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
$named = $rz->getFloat(min: 0.0, max: 1.0);
echo 'named_getFloat=', is_float($named) && $named >= 0.0 && $named < 1.0 ? 'ok' : 'fail', "\n";
$pos = $rz->getFloat(0.0, 1.0);
echo 'pos_getFloat=', is_float($pos) && $pos >= 0.0 && $pos < 1.0 ? 'ok' : 'fail', "\n";

$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
echo 'named_gbfs=', bin2hex($rz->getBytesFromString(string: 'abcdef', length: 8)), "\n";
$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
echo 'pos_gbfs=', bin2hex($rz->getBytesFromString('abcdef', 8)), "\n";
?>
--EXPECT--
getFloat arity=3 req=2 return=float
  min type=float optional=0
  max type=float optional=0
  boundary type=Random\IntervalBoundary optional=1
getBytesFromString arity=2 req=2 return=string
  string type=string optional=0
  length type=int optional=0
nextFloat arity=0 req=0 return=float
named_getFloat=ok
pos_getFloat=ok
named_gbfs=6665626364616561
pos_gbfs=6665626364616561
