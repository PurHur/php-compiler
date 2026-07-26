<?php
// #23472: nested recursive call with BinaryOp first arg — AOT must not clobber both ARG_SENDs
function Ack(int $m, int $n): int {
    if ($m == 0) return $n + 1;
    if ($n == 0) return Ack($m - 1, 1);
    return Ack($m - 1, Ack($m, ($n - 1)));
}
echo Ack(3, 3), "\n";
function f(int $a, int $b): int {
    if ($a <= 0) return $b;
    return f($a - 1, f($a - 1, $b + 1));
}
echo f(3, 0), "\n";
