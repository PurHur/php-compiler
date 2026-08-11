<?php
/**
 * #26096 — bcceil/bcfloor/bcround Reflection arity + named args (ext/bcmath/bcmath.stub.php).
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26096_bcceil_bcfloor_bcround_reflection.php
 */
foreach (['bcceil', 'bcfloor', 'bcround'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $parts = [];
    foreach ($rf->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $d = '';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $def = $p->getDefaultValue();
            $d = '='.(\is_object($def) ? $def::class.'::'.$def->name : var_export($def, true));
        } elseif ($p->isOptional()) {
            $d = '=?';
        }
        $parts[] = $p->getName().':'.$t.$d;
    }
    $rt = $rf->hasReturnType() ? (string) $rf->getReturnType() : '-';
    echo $fn, ' arity=', $rf->getNumberOfParameters(), ' [', implode(',', $parts), '] -> ', $rt, "\n";
}
echo 'named_ceil=', bcceil(num: '1.2'), "\n";
echo 'named_floor=', bcfloor(num: '1.9'), "\n";
echo 'named_round=', bcround(num: '1.55', precision: 1, mode: RoundingMode::HalfAwayFromZero), "\n";
echo 'pos_ceil=', bcceil('1.2'), "\n";
