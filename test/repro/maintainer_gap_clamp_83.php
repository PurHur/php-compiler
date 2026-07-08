<?php

declare(strict_types=1);

$fail = 0;
if (3 !== clamp(5, 1, 3)) {
    echo "fail: above max\n";
    ++$fail;
}
if (1 !== clamp(0, 1, 3)) {
    echo "fail: below min\n";
    ++$fail;
}
if (2 !== clamp(2, 1, 3)) {
    echo "fail: in range\n";
    ++$fail;
}

echo 0 === $fail ? "ok\n" : "fail\n";
exit($fail);
