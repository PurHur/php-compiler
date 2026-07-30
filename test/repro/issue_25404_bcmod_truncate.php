<?php
declare(strict_types=1);
// Repro #25404 — bcmod remainder must use truncate-toward-zero quotient (php-src divmod.c).
foreach ([['5', '3', '2'], ['5', '2', '1'], ['7', '4', '3'], ['10', '3', '1'], ['-5', '3', '-2']] as [$a, $b, $want]) {
    $got = bcmod($a, $b);
    echo "bcmod($a,$b)=$got want=$want ", $got === $want ? 'ok' : 'FAIL', "\n";
}
echo 'bcdiv(5,3,0)=', bcdiv('5', '3', 0), "\n";
