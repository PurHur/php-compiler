<?php
class C {
    private(set) int $x = 1;
    private(set) array $arr = [];
}

$c = new C();
echo 'before ref bind: ', $c->x, "\n";
try {
    $ref = &$c->x;
    echo "ref bind succeeded\n";
    $ref = 99;
    echo 'after ref assign: ', $c->x, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $c->arr[] = 1;
    echo 'array append count: ', count($c->arr), "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
