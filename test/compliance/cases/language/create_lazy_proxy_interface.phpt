--TEST--
Language: newLazyProxy() on interfaces delegates to factory instance (#9999, #28414)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsLazyObjectFactories()) {
    die('skip ReflectionClass lazy factories require PHP 8.4 forward profile');
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
$rc = new ReflectionClass(I::class);
$o = $rc->newLazyProxy(static function () use (&$calls): C {
    ++$calls;
    return new C();
});
echo $o->f(), "\n";
echo $calls, "\n";
echo $o->f(), "\n";
echo $calls, "\n";

try {
    (new ReflectionClass(I::class))->newLazyGhost(static function (): void {});
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
