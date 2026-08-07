--TEST--
Language: ReflectionClass::newLazyGhost / newLazyProxy factories (#6708, #28414)
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
// Free functions stay off (php-src has ReflectionClass::newLazy* only).
var_export(function_exists('createLazyGhost'));
echo "\n";
var_export(function_exists('createLazyProxy'));
echo "\n";

class C {
    private function __construct() {}
    public string $name = 'unset';
}
$rc = new ReflectionClass(C::class);
$ghost = $rc->newLazyGhost(function (C $c): void {
    $c->name = 'lazy';
});
echo $ghost->name, "\n";
echo $ghost->name, "\n";

class Svc {
    public string $id = '';
    public function __construct(string $id = '') {
        $this->id = $id;
    }
}
$rcSvc = new ReflectionClass(Svc::class);
$proxy = $rcSvc->newLazyProxy(static fn (): Svc => new Svc('proxy'));
echo $proxy->id, "\n";

var_export(method_exists(ReflectionClass::class, 'newLazyGhost'));
echo "\n";
var_export(method_exists(ReflectionClass::class, 'newLazyProxy'));
echo "\n";
?>
--EXPECT--
false
false
lazy
lazy
proxy
true
true
