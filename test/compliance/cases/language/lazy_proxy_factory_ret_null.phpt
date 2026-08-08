--TEST--
Language: newLazyProxy factory returning null → TypeError (#29170, Zend/zend_lazy_objects.c)
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
$proxy = $r->newLazyProxy(function ($obj) {
    return null;
});
try {
    echo $proxy->x;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";

$ok = $r->newLazyProxy(function (): C {
    $o = new C();
    $o->x = 9;
    return $o;
});
echo $ok->x, "\n";
?>
--EXPECT--
TypeError:Lazy proxy factory must return an instance of a class compatible with C, null returned
9
