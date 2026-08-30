<?php
// #35788 AOT leftover of #32701 / #26407 — runtime class string (not a compile-time literal).
class C
{
    public $x = 1;
}

function check(string $n): void
{
    echo property_exists($n, 'x') ? 'yes' : 'no', "\n";
    echo property_exists($n, 'missing') ? 'yes' : 'no', "\n";
}

check('C');
