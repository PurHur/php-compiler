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

$o = createLazyProxy(I::class, static fn (I $proxy): C => new C());
echo $o->f(), "\n";

try {
    createLazyGhost(I::class, static function (): void {});
    echo "ghost-uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
