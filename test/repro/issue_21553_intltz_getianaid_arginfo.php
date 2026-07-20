<?php
declare(strict_types=1);

/**
 * #21553 — IntlTimeZone::getIanaID Reflection arginfo (ICU≥74 hosts).
 */
$oop = method_exists('IntlTimeZone', 'getIanaID');
$proc = function_exists('intltz_get_iana_id');
echo 'advertised=', ($oop && $proc) ? 'yes' : 'no', "\n";
if (!$oop) {
    echo "skip_icu_lt_74\n";
    exit(0);
}
$m = new ReflectionMethod('IntlTimeZone', 'getIanaID');
echo 'params=', $m->getNumberOfParameters(), "\n";
echo 'required=', $m->getNumberOfRequiredParameters(), "\n";
echo 'static=', $m->isStatic() ? 'yes' : 'no', "\n";
echo 'param_names=', implode(',', array_map(static fn ($p) => $p->getName(), $m->getParameters())), "\n";
$t = $m->getParameters()[0]->getType();
echo 'param0_type=', null === $t ? 'none' : (string) $t, "\n";
echo 'call=', IntlTimeZone::getIanaID('Europe/Kiev'), "\n";
$fn = new ReflectionFunction('intltz_get_iana_id');
echo 'fn_params=', $fn->getNumberOfParameters(), "\n";
