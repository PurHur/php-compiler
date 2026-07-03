<?php
declare(strict_types=1);

function hold(array $v): void
{
    json_encode($v);
}

hold([]);

$r = array_pad([1, 2], -4, 0);
$expected = [0, 0, 1, 2];
if ($r !== $expected) {
    echo 'fail: got ', var_export($r, true), "\n";
    exit(1);
}
echo "ok\n";
