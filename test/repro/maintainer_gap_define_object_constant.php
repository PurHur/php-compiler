<?php

declare(strict_types=1);

define('MY_OBJ', (object) ['x' => 1, 'y' => 2]);

$fail = [];

if (MY_OBJ->x !== 1) {
    $fail[] = 'MY_OBJ->x expected 1, got ' . var_export(MY_OBJ->x, true);
}
if (MY_OBJ->y !== 2) {
    $fail[] = 'MY_OBJ->y expected 2, got ' . var_export(MY_OBJ->y, true);
}

$json = json_encode(MY_OBJ);
if ($json !== '{"x":1,"y":2}') {
    $fail[] = 'json_encode expected {"x":1,"y":2}, got ' . var_export($json, true);
}

$vars = get_object_vars(MY_OBJ);
if ($vars !== ['x' => 1, 'y' => 2]) {
    $fail[] = 'get_object_vars expected [x=>1,y=>2], got ' . var_export($vars, true);
}

if ($fail !== []) {
    echo implode("\n", $fail) . "\n";
    exit(1);
}

echo "ok\n";
