<?php

declare(strict_types=1);

$expected = [['a', 'b']];

$result = array_map('explode', [','], ['a,b']);
if ($result !== $expected) {
    echo 'fail: got ', var_export($result, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

echo "ok\n";
