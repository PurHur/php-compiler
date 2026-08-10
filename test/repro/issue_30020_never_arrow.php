<?php
try {
    $f = fn(): never => 1;
    $f();
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
