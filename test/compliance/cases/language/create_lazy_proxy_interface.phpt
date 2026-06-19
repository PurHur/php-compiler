--TEST--
Language: createLazyProxy() on interfaces delegates to factory instance (#9999)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip createLazyProxy requires PHP 8.4+');
}
?>
--FILE--
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

$calls = 0;
$o = createLazyProxy(I::class, static function (I $proxy) use (&$calls): C {
    ++$calls;
    return new C();
});
echo $o->f(), "\n";
echo $calls, "\n";
echo $o->f(), "\n";
echo $calls, "\n";

try {
    createLazyGhost(I::class, static function (): void {});
    echo "ghost-uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
42
1
42
1
LogicException
