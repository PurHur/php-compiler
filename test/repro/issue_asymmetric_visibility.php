<?php
class C {
    public private(set) string $name = 'Alice';
}
$c = new C();
echo $c->name, "\n";
try {
    $c->name = 'Bob';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
