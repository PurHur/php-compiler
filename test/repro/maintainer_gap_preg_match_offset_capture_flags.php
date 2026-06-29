<?php

declare(strict_types=1);

$fail = 0;

preg_match('/a/', 'abc', $m);
if (!isset($m[0]) || 'a' !== $m[0]) {
    echo "FAIL: preg_match 3-arg unset \$m\n";
    ++$fail;
}

preg_match('/a/', 'abc', $m2, PREG_OFFSET_CAPTURE);
if (!isset($m2[0][0]) || 'a' !== $m2[0][0] || 0 !== $m2[0][1]) {
    echo "FAIL: preg_match 4-arg unset \$m2 flags\n";
    var_export($m2);
    echo "\n";
    ++$fail;
}

preg_match_all('/(\d)/', 'a1b2', $all, PREG_OFFSET_CAPTURE);
if (!isset($all[1][0][0]) || '1' !== $all[1][0][0]) {
    echo "FAIL: preg_match_all 4-arg unset \$all\n";
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
