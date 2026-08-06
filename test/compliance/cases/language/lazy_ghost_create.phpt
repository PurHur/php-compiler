--TEST--
Language: LazyGhostTrait createLazyGhost / markLazyObjectAsInitialized (#6531)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip LazyGhostTrait requires PHP 8.4+');
}
?>
--FILE--
<?php
class C {
    use LazyGhostTrait;
    public string $name = 'init';
}
$c = C::createLazyGhost(function (C $o): void {
    $o->name = 'lazy';
});
var_dump($c->name);

$ghost = C::createLazyGhost(function (C $o): void {
    $o->name = 'never';
});
$ghost->markLazyObjectAsInitialized();
var_dump($ghost->name);

class Plain {}
try {
    Plain::createLazyGhost(function (): void {});
    echo "no error\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
string(4) "lazy"
string(4) "init"
Error: Call to undefined method Plain::createLazyGhost()
