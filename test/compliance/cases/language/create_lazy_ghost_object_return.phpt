--TEST--
Language: newLazyGhost() initializer object return → TypeError (#29169, Zend/zend_lazy_objects.c)
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
class C {
    public int $x = 1;
}
$r = new ReflectionClass(C::class);
$g = $r->newLazyGhost(function ($obj) {
    return new C();
});
try {
    echo $g->x;
    echo "NO_ERROR\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";

$ok = $r->newLazyGhost(function ($obj) {
    return;
});
echo $ok->x, "\n";
?>
--EXPECT--
TypeError:Lazy object initializer must return NULL or no value
1
