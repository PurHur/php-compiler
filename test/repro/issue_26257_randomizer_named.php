<?php
/**
 * #26257 — Random\Randomizer getFloat/getBytesFromString/nextFloat Reflection
 * + named args (ext/random/randomizer.stub.php).
 *
 * PROFILE≥8.3: these methods exist. Named min/max/string/length must bind.
 */
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
            ' optional=', (int) $p->isOptional();
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $def = $p->getDefaultValue();
            echo ' default=', $def instanceof UnitEnum ? $def::class.'::'.$def->name : var_export($def, true);
        }
        echo "\n";
    }
}

$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
$named = $rz->getFloat(min: 0.0, max: 1.0);
echo 'named_getFloat=', is_float($named) && $named >= 0.0 && $named < 1.0 ? 'ok' : 'fail', "\n";
$pos = $rz->getFloat(0.0, 1.0);
echo 'pos_getFloat=', is_float($pos) && $pos >= 0.0 && $pos < 1.0 ? 'ok' : 'fail', "\n";

$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
$namedBytes = $rz->getBytesFromString(string: 'abcdef', length: 8);
echo 'named_getBytesFromString=', bin2hex($namedBytes), "\n";
$rz = new Random\Randomizer(new Random\Engine\Mt19937(1));
$posBytes = $rz->getBytesFromString('abcdef', 8);
echo 'pos_getBytesFromString=', bin2hex($posBytes), "\n";

$next = $rz->nextFloat();
echo 'nextFloat=', is_float($next) && $next >= 0.0 && $next < 1.0 ? 'ok' : 'fail', "\n";
