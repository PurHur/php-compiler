<?php
/**
 * #32442 — boxed unary minus is zendi_negate_function, not sitofp-to-double.
 * Literal -"5" already folds; runtime string/long boxes used to print float.
 */
function letters()
{
    return '5';
}
function five()
{
    return 5;
}
function onePointFive()
{
    return 1.5;
}

$s = letters();
var_dump(-$s);

$n = five();
var_dump(-$n);

$f = onePointFive();
var_dump(-$f);

$local = '5';
var_dump(-$local);
