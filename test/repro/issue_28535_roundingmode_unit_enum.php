<?php

/**
 * #28535 — RoundingMode is a Zend unit enum; no PHP_ROUND_CEILING/FLOOR/TOWARD/AWAY.
 */
error_reporting(E_ALL);
echo 'backed=', (new ReflectionEnum(RoundingMode::class))->isBacked() ? '1' : '0', "\n";
@$v = RoundingMode::TowardsZero->value;
echo 'value=', var_export($v, true), "\n";
foreach ([
    'PHP_ROUND_HALF_UP',
    'PHP_ROUND_CEILING',
    'PHP_ROUND_FLOOR',
    'PHP_ROUND_TOWARD_ZERO',
    'PHP_ROUND_AWAY_FROM_ZERO',
] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'undef', "\n";
}
$p = (new ReflectionFunction('round'))->getParameters()[2];
echo 'mode_type=', (string) $p->getType(), "\n";
echo 'default_const=', $p->isDefaultValueConstant() ? '1' : '0', "\n";
echo 'default_name=', var_export($p->getDefaultValueConstantName(), true), "\n";
$d = $p->getDefaultValue();
echo 'default=', $d instanceof RoundingMode ? 'RoundingMode::'.$d->name : var_export($d, true), "\n";
echo 'round=', round(-1.6, 0, RoundingMode::TowardsZero), "\n";
