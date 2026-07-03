<?php

declare(strict_types=1);

$lower = mb_strtolower('İ', 'UTF-8');
$expected = "i\xCC\x87";
if ($lower !== $expected) {
    echo 'fail: got ', bin2hex($lower), ' expected ', bin2hex($expected), "\n";
    exit(1);
}

echo "ok\n";
