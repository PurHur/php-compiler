<?php
// Isolate: literal echo between two top-level Ack calls (#23472).
function Ack(int $m, int $n): int
{
    if ($m == 0) {
        return $n + 1;
    }
    if ($n == 0) {
        return Ack($m - 1, 1);
    }

    return Ack($m - 1, Ack($m, ($n - 1)));
}
echo Ack(3, 3);
echo "\n";
echo Ack(3, 3);
