<?php

declare(strict_types=1);

/**
 * Repro for #15750 — property_exists() after unset() on dynamic properties.
 */

class UserDyn
{
}

$o = new stdClass();
$o->x = 1;
unset($o->x);

$c = new UserDyn();
$c->dyn = 1;
unset($c->dyn);

$stdOk = !property_exists($o, 'x')
    && !isset($o->x)
    && !array_key_exists('x', get_object_vars($o));

$userOk = !property_exists($c, 'dyn')
    && !isset($c->dyn)
    && !array_key_exists('dyn', get_object_vars($c));

echo 'property_exists_std=', property_exists($o, 'x') ? '1' : '0', "\n";
echo 'property_exists_user=', property_exists($c, 'dyn') ? '1' : '0', "\n";
echo 'isset_std=', isset($o->x) ? '1' : '0', "\n";
echo 'isset_user=', isset($c->dyn) ? '1' : '0', "\n";
echo 'vars_std=', array_key_exists('x', get_object_vars($o)) ? '1' : '0', "\n";
echo 'vars_user=', array_key_exists('dyn', get_object_vars($c)) ? '1' : '0', "\n";

exit($stdOk && $userOk ? 0 : 1);
