<?php
declare(strict_types=1);

$fail = 0;

list(, $y) = [1, 2];
if (2 !== $y) {
    echo "list skip: expected 2, got ";
    var_export($y);
    echo "\n";
    ++$fail;
}

[, $y2] = [1, 2];
if (2 !== $y2) {
    echo "short skip: expected 2, got ";
    var_export($y2);
    echo "\n";
    ++$fail;
}

[, $b, ] = [1, 2, 3];
if (2 !== $b) {
    echo "middle skip: expected 2, got ";
    var_export($b);
    echo "\n";
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
