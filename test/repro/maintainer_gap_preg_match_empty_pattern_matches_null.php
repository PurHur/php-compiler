<?php

declare(strict_types=1);

preg_match('', 'x', $m);
if (null !== $m) {
    echo 'fail: preg_match empty pattern $matches is array (', gettype($m), ') not NULL', "\n";
    exit(1);
}
preg_match_all('', 'x', $m2);
if (null !== $m2) {
    echo 'fail: preg_match_all empty pattern $matches is array (', gettype($m2), ') not NULL', "\n";
    exit(1);
}
echo "ok\n";
