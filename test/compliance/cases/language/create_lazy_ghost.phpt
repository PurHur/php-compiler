--TEST--
Language: createLazyGhost() procedural factory (#6708)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip createLazyGhost requires PHP 8.4+');
}
?>
--FILE--
<?php
class C {
    private function __construct() {}
    public string $name = 'unset';
}
$ghost = createLazyGhost(C::class, function (C $c): void {
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
$proxy = createLazyProxy(Svc::class, static fn (Svc $o): Svc => new Svc('proxy'));
echo $proxy->id, "\n";

try {
    createLazyGhost('NoSuchClass', function (): void {});
    echo "no error\n";
} catch (ValueError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
lazy
lazy
proxy
ValueError: createLazyGhost(): Argument #1 ($class) must be a valid class name, 'NoSuchClass' given
