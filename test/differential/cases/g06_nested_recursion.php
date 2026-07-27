<?php
// #23472: Ack(3,n) segfaulted (exit 139) — nested calls where one call's result
// feeds another call's argument. #23482: the untyped two-self-call shape crashed
// the compiler outright.
function ack($m, $n) {
    if ($m == 0) { return $n + 1; }
    if ($n == 0) { return ack($m - 1, 1); }
    return ack($m - 1, ack($m, $n - 1));
}
echo ack(2, 3), "\n";
function fibu($a) { return $a < 2 ? $a : fibu($a - 1) + fibu($a - 2); }
echo fibu(12), "\n";
