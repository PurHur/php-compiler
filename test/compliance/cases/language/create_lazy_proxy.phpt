--TEST--
Language: ReflectionClass::newLazyProxy() factory (#7387, #28414)
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
    public int $x = 0;
}
$rc = new ReflectionClass(C::class);
$c = $rc->newLazyProxy(function (): C {
    $o = new C();
    $o->x = 2;
    return $o;
});
echo $c->x, "\n";

class Svc {
    public string $id = '';
    public function __construct(string $id = '') {
        $this->id = $id;
    }
}
$proxy = (new ReflectionClass(Svc::class))->newLazyProxy(static fn (): Svc => new Svc('proxy'));
echo $proxy->id, "\n";
?>
--EXPECT--
2
proxy
