<?php
function g(): Generator {
    return 1;
    yield;
}
$g = g();
try {
    var_dump($g->getReturn());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
