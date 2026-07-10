<?php

declare(strict_types=1);

define('MY_OBJ', (object) ['x' => 1, 'y' => 2]);

if (1 !== MY_OBJ->x || 2 !== MY_OBJ->y) {
    echo "FAIL: MY_OBJ property values\n";
    exit(1);
}

$encoded = json_encode(MY_OBJ);
if ('{"x":1,"y":2}' !== $encoded) {
    echo "FAIL: json_encode(MY_OBJ) got {$encoded}\n";
    exit(1);
}

$vars = get_object_vars(MY_OBJ);
if (['x' => 1, 'y' => 2] !== $vars) {
    echo "FAIL: get_object_vars(MY_OBJ)\n";
    exit(1);
}

echo "ok\n";
