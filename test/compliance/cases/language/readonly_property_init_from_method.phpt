--TEST--
Language: readonly property first init from instance method (not only ctor) (#23475, zend_readonly.c)
--FILE--
<?php
class C {
    public readonly int $x;
    public function set(int $v): void { $this->x = $v; }
    public function again(int $v): void { $this->x = $v; }
}
class E extends C {
    public function childSet(int $v): void { $this->x = $v; }
}

$c = new C();
try {
    var_export($c->x);
    echo "\n";
} catch (Throwable $e) {
    // Prefer getMessage before get_class — get_class-first can empty later Error messages on VM.
    echo 'read:Error:', $e->getMessage(), "\n";
}
try {
    $c->set(1);
    echo 'set_ok:', $c->x, "\n";
} catch (Throwable $e) {
    echo 'set:Error:', $e->getMessage(), "\n";
}
try {
    $c->again(2);
    echo 'again_ok:', $c->x, "\n";
} catch (Throwable $e) {
    echo 'again:Error:', $e->getMessage(), "\n";
}

$c2 = new C();
try {
    $c2->x = 1;
    echo "ext_ok\n";
} catch (Throwable $e) {
    echo 'ext:Error:', $e->getMessage(), "\n";
}

$child = new E();
try {
    $child->childSet(1);
    echo 'child_ok:', $child->x, "\n";
} catch (Throwable $e) {
    echo 'child:Error:', $e->getMessage(), "\n";
}
--EXPECT--
read:Error:Typed property C::$x must not be accessed before initialization
set_ok:1
again:Error:Cannot modify readonly property C::$x
ext:Error:Cannot initialize readonly property C::$x from global scope
child:Error:Cannot initialize readonly property C::$x from scope E
