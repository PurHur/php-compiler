<?php

declare(strict_types=1);

$expected = ['a' => 10, 'b' => 20];

$inline = array_combine(array_keys(['a' => 1, 'b' => 2]), [10, 20]);
if ($inline !== $expected) {
    echo 'fail inline: got ', var_export($inline, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

$k = array_keys(['a' => 1, 'b' => 2]);
$variable = array_combine($k, [10, 20]);
if ($variable !== $expected) {
    echo 'fail variable: got ', var_export($variable, true), "\n";
    exit(1);
}

echo "ok\n";
