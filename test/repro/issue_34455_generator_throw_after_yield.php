<?php
function g() {
    yield 1;
    throw new Exception('x');
}
try {
    foreach (g() as $v) {
        echo 'V:', $v, "\n";
    }
    echo "AFTER\n";
} catch (Exception $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
$g = g();
echo 'cur:', $g->current(), "\n";
try {
    $g->next();
    echo "AFTER_NEXT\n";
} catch (Exception $e) {
    echo 'caught_next:', $e->getMessage(), "\n";
}
