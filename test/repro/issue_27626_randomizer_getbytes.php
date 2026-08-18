<?php
/**
 * #27626 — Random\Randomizer::getBytes Reflection + named $length
 * (ext/random/randomizer.stub.php — public function getBytes(int $length): string).
 *
 * Positional already works; Zend named length: must bind.
 */
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
