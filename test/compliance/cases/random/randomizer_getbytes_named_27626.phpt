--TEST--
Stdlib: Random\Randomizer::getBytes named length + Reflection (#27626, ext/random/randomizer.stub.php)
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionMethod(Random\Randomizer::class, 'getBytes');
echo 'arity=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters();
echo ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
echo "\n";
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    echo '  ', $p->getName(),
        ' type=', $t ? (string) $t : 'none',
        ' optional=', (int) $p->isOptional(), "\n";
}

$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
$named = $rz->getBytes(length: 4);
echo 'named_len=', strlen($named), ' hex=', bin2hex($named), "\n";
$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
$pos = $rz->getBytes(4);
echo 'pos_len=', strlen($pos), ' hex=', bin2hex($pos), "\n";
echo 'match=', $named === $pos ? 'yes' : 'no', "\n";
?>
--EXPECT--
arity=1 req=1 return=string
  length type=int optional=0
named_len=4 hex=25f4c16a
pos_len=4 hex=25f4c16a
match=yes
