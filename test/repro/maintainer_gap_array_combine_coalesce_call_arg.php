<?php

declare(strict_types=1);

$keys = ['a', 'b'];
$values = [1, 2];
$result = array_combine($keys ?? [], $values ?? []);
if (!is_array($result)) {
    echo "fail: not array\n";
    exit(1);
}
if ($result !== ['a' => 1, 'b' => 2]) {
    echo 'fail: got ';
    var_export($result);
    echo "\n";
    exit(1);
}
echo "ok\n";
