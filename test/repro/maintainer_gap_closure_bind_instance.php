<?php
declare(strict_types=1);

// Maintainer repro for #11857 — Closure::bind instance $this property read (Zend/zend_closures.c).
class T {
    public int $x = 1;
}

$c = function (): int {
    return $this->x;
};
$bound = Closure::bind($c, new T(), T::class);
try {
    $v = $bound();
    echo ($v === 1 ? 'ok' : 'fail: got ' . var_export($v, true)), "\n";
} catch (Throwable $e) {
    echo 'fail: ', get_class($e), ': ', $e->getMessage(), "\n";
}
