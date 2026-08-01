--TEST--
Language: readonly class ctor may first-init trait-imported readonly property (#26593, zend_readonly.c)
--FILE--
<?php
declare(strict_types=1);

trait T {
    public readonly int $x;
}

readonly class R {
    use T;
    public function __construct(int $x) {
        $this->x = $x;
    }
    public function bump(): void {
        $this->x = 2;
    }
}

class Parent_ {
    use T;
    public function __construct(int $x) {
        $this->x = $x;
    }
}

class Child extends Parent_ {
    public function __construct(int $x) {
        $this->x = $x;
    }
}

$r = new R(1);
echo 'init=', $r->x, "\n";
echo 'decl=', (new ReflectionProperty(R::class, 'x'))->getDeclaringClass()->getName(), "\n";

try {
    $r->bump();
    echo "bump: ok\n";
} catch (Throwable $e) {
    echo 'bump: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $r->x = 3;
    echo "ext: ok\n";
} catch (Throwable $e) {
    echo 'ext: ', get_class($e), ': ', $e->getMessage(), "\n";
}

echo 'parent=', (new Parent_(4))->x, "\n";
try {
    new Child(5);
    echo "child: ok\n";
} catch (Throwable $e) {
    echo 'child: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
init=1
decl=R
bump: Error: Cannot modify readonly property R::$x
ext: Error: Cannot modify readonly property R::$x
parent=4
child: Error: Cannot initialize readonly property Parent_::$x from scope Child
