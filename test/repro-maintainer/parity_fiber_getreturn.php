<?php
$f = new Fiber(function (): int {
    return 42;
});
$f->start();
try {
    var_dump($f->getReturn());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
