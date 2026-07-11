<?php

declare(strict_types=1);

$fail = 0;

preg_match('/(a(b))/', 'ab', $m, PREG_OFFSET_CAPTURE);
if ('ab' !== $m[1][0] || 0 !== $m[1][1]) {
    echo 'FAIL: outer capture group 1 expected [ab,0] got ', var_export($m[1], true), "\n";
    ++$fail;
}
if ('b' !== $m[2][0] || 1 !== $m[2][1]) {
    echo 'FAIL: inner capture group 2 expected [b,1] got ', var_export($m[2], true), "\n";
    ++$fail;
}

preg_match_all('/(\d)/', 'a1b2', $all, PREG_OFFSET_CAPTURE);
if ('1' !== $all[1][0][0] || 1 !== $all[1][0][1]) {
    echo 'FAIL: preg_match_all first digit expected [1,1] got ', var_export($all[1][0], true), "\n";
    ++$fail;
}
if ('2' !== $all[1][1][0] || 3 !== $all[1][1][1]) {
    echo 'FAIL: preg_match_all second digit expected [2,3] got ', var_export($all[1][1], true), "\n";
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
