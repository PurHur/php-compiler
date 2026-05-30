--TEST--
language: Closure::fromCallable() instance method with visibility (issue #3266)
--FILE--
<?php
class C {
    private function secret(): string { return 'ok'; }
    public function get(): Closure {
        return Closure::fromCallable([$this, 'secret']);
    }
}
$c = new C();
echo ($c->get())(), "\n";
--EXPECT--
ok
