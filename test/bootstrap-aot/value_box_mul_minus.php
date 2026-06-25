<?php
/** Issue #11392: JIT must lower value×value TYPE_MUL/TYPE_MINUS (bin/compile.php spine). */

function pick(int $n): int|string
{
    return $n;
}

$a = pick(6);
$b = pick(7);
$c = pick(20);
echo ($a * $b), "\n";
echo ($c - $a), "\n";
