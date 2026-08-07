--TEST--
Language: newLazyGhost() ghost initializer object return ignored (#12309, #28414)
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
    public int $v = 0;
}
$rc = new ReflectionClass(C::class);
$ghost = $rc->newLazyGhost(function (C $o) {
    $o->v = 42;
    return $o;
});
echo $ghost->v, "\n";
?>
--EXPECT--
42
