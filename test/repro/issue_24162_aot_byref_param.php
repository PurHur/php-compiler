<?php
/**
 * Repro #24162 — AOT by-reference parameters must update the caller (ZEND_SEND_REF).
 *
 * Expected (Zend / VM / JIT / AOT):
 *   9
 *   6
 *   O:9
 */
function assignNine(int &$x): void
{
    $x = 9;
}

function increment(int &$x): void
{
    $x++;
}

function outer(): void
{
    $n = 1;
    assignNine($n);
    echo 'O:', $n, "\n";
}

$a = 1;
assignNine($a);
echo $a, "\n";

$b = 5;
increment($b);
echo $b, "\n";

outer();
