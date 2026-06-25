--TEST--
Language: value×value TYPE_MUL and TYPE_MINUS JIT lowering (#11392)
--FILE--
<?php
function pick(int $n): int|string
{
    return $n;
}

$a = pick(6);
$b = pick(7);
$c = pick(20);
echo ($a * $b), "\n";
echo ($c - $a), "\n";
--EXPECT--
42
14
