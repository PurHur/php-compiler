<?php

declare(strict_types=1);

$expected = [0 => 'a'];

$inline = array_intersect_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9, 'c' => 3]));
if ($inline !== $expected) {
    echo 'fail inline: got ', var_export($inline, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

$left = array_keys(['a' => 1, 'b' => 2]);
$right = array_keys(['a' => 9, 'c' => 3]);
$variable = array_intersect_assoc($left, $right);
if ($variable !== $expected) {
    echo 'fail variable: got ', var_export($variable, true), "\n";
    exit(1);
}

echo "ok\n";
