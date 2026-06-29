<?php

declare(strict_types=1);

$expected = [1 => 'b'];

$inline = array_diff_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]));
if ($inline !== $expected) {
    echo 'fail inline: got ', var_export($inline, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

$left = array_keys(['a' => 1, 'b' => 2]);
$right = array_keys(['a' => 9]);
$variable = array_diff_assoc($left, $right);
if ($variable !== $expected) {
    echo 'fail variable: got ', var_export($variable, true), "\n";
    exit(1);
}

echo "ok\n";
