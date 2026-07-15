<?php

declare(strict_types=1);

$expected = [1 => 'b'];
$inline = array_udiff_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]), 'strcmp');
if ($inline !== $expected) {
    echo 'fail: ', var_export($inline, true), "\n";
    exit(1);
}
echo "ok\n";
