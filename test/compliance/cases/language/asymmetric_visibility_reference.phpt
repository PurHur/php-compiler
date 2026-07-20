--TEST--
PHP 8.4 asymmetric visibility: reference bind and array append follow set visibility (#7070)
--FILE--
<?php
class C {
    public (private(set)) int $x = 1;
    public (private(set)) array $arr = [];
}

$c = new C();
echo "before ref bind: {$c->x}\n";
try {
    $ref = &$c->x;
    echo "ref bind succeeded\n";
    $ref = 99;
    echo "after ref assign: {$c->x}\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $c->arr[] = 1;
    echo 'array append count: ', count($c->arr), "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
before ref bind: 1
Error: Cannot modify private(set) property C::$x from global scope
Error: Cannot modify private(set) property C::$arr from global scope
