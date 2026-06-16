<?php
class C {
    public int $n = 1;
}
$obj = new C();
try {
    $c = clone $obj;
    var_dump($c->n);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
