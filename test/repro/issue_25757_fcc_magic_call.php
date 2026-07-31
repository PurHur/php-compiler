<?php
// #25757 — FCC / Closure::fromCallable missing methods must invoke __call / __callStatic
class A {
    public function __call($n, $a) {
        return "call:$n:" . implode(",", $a);
    }
    public static function __callStatic($n, $a) {
        return "cs:$n:" . implode(",", $a);
    }
}
$a = new A;
try {
    $c = $a->missing(...);
    echo $c("x", "y"), "\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
try {
    $c = A::missing(...);
    echo $c("z"), "\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
try {
    echo Closure::fromCallable([$a, "missing"])("q"), "\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
try {
    echo Closure::fromCallable([A::class, "missing"])("w"), "\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
