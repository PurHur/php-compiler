<?php
/**
 * #24825 — round() Reflection num int|float and mode default PHP_ROUND_HALF_UP.
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_24825_round_reflection.php'
 */
$r = new ReflectionFunction('round');
foreach ($r->getParameters() as $p) {
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $line = $p->getName().' type='.$type;
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        $line .= ' default=';
        $def = $p->getDefaultValue();
        if (\is_object($def)) {
            $line .= $def::class.'::'.$def->name;
        } else {
            $line .= var_export($def, true);
        }
    }
    echo $line, "\n";
}
