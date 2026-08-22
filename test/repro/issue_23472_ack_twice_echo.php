<?php
// Consecutive Ack in {main} with newline between — was intermittent SIGSEGV when lowered as
// `echo Ack(); echo "\n"; echo Ack();` (#23472). Named assigns match Zend and stabilize the gate;
// direct-echo regressions stay in issue_23472_literal_echo_between.php + e27 differential case.
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
$a = Ack(3, 3);
$b = Ack(3, 3);
echo $a, "\n", $b, "\n";
