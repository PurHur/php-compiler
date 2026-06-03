<?php
class B {
    public string $v = 'ok';
}
class A {
    public ?B $b;
}
$a = new A();
echo $a->b?->v ?? 'null', "\n";
try {
    echo $a->b, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
