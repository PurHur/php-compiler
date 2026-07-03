<?php

declare(strict_types=1);

$left = str_pad('x', 5, ' ', STR_PAD_LEFT);
$named = str_pad(pad_type: STR_PAD_LEFT, string: 'x', length: 5);

if ($left !== $named) {
    echo 'fail: got ', var_export($named, true), ' expected ', var_export($left, true), "\n";
    exit(1);
}

echo "ok\n";
