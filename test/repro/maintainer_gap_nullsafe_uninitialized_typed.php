<?php
class B {
    public int $x = 1;
}
class A {
    public ?B $b;
}
$a = new A();
echo ($a?->b?->x ?? 'n'), "\n";

class A2 {
    public B $b;
}
class B2 {
    public int $x = 1;
}
$a2 = new A2();
try {
    var_export($a2?->b?->x);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
