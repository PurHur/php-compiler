--TEST--
Language: engine-thrown Error/TypeError/DivisionByZeroError getCode() defaults to 0 (issue #22945)
--FILE--
<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    (new stdClass)->nope();
} catch (Error $e) {
    echo 'undef ', get_class($e), ' ', $e->getCode(), "\n";
}

try {
    $f = function (int $x) {};
    $f('a');
} catch (TypeError $e) {
    echo 'type ', get_class($e), ' ', $e->getCode(), "\n";
}

try {
    1 / 0;
} catch (DivisionByZeroError $e) {
    echo 'div ', get_class($e), ' ', $e->getCode(), "\n";
}

try {
    throw new TypeError('t');
} catch (TypeError $e) {
    echo 'user ', get_class($e), ' ', $e->getCode(), "\n";
}

try {
    throw new Error('x', 7);
} catch (Error $e) {
    echo 'userErr ', get_class($e), ' ', $e->getCode(), "\n";
}
--EXPECT--
undef Error 0
type TypeError 0
div DivisionByZeroError 0
user TypeError 0
userErr Error 7
