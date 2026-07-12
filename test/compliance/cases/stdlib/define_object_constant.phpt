--TEST--
define() object constant preserves dynamic properties (#17676, basic_functions.c / zend_constants.c)
--FILE--
<?php

declare(strict_types=1);

define('MY_OBJ', (object) ['x' => 1, 'y' => 2]);

if (MY_OBJ->x !== 1) {
    echo 'bad x';
    exit(1);
}
if (MY_OBJ->y !== 2) {
    echo 'bad y';
    exit(1);
}

$json = json_encode(MY_OBJ);
if ($json !== '{"x":1,"y":2}') {
    echo 'bad json: ', $json;
    exit(1);
}

$vars = get_object_vars(MY_OBJ);
if ($vars !== ['x' => 1, 'y' => 2]) {
    var_export($vars);
    exit(1);
}

echo "ok\n";
--EXPECT--
ok
