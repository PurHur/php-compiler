<?php
declare(strict_types=1);

interface I {
    public function f(): int;
}

class C implements I {
    public function f(): int {
        return 42;
    }
}

$o = (new ReflectionClass(I::class))->newLazyProxy(static fn (): C => new C());
echo $o->f(), "\n";

try {
    (new ReflectionClass(I::class))->newLazyGhost(static function (): void {});
    echo "ghost-uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
