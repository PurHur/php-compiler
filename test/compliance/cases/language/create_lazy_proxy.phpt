--TEST--
Language: createLazyProxy() factory receives proxy instance (#7387)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip createLazyProxy requires PHP 8.4+');
}
?>
--FILE--
<?php
class C {
    public int $x = 0;
}
$c = createLazyProxy(C::class, function (C $o): C {
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
$proxy = createLazyProxy(Svc::class, static fn (Svc $o): Svc => new Svc('proxy'));
echo $proxy->id, "\n";
?>
--EXPECT--
2
proxy
