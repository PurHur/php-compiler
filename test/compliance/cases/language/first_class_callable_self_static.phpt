--TEST--
Language: self::/static:: instanceMethod(...) first-class callable (#26630, zend_ast.c)
--FILE--
<?php
class ParentFcc {
    public function label(): string {
        return 'parent';
    }
    public function viaSelf(): string {
        $f = self::label(...);
        return $f();
    }
    public function viaStatic(): string {
        $f = static::label(...);
        return $f();
    }
}
class ChildFcc extends ParentFcc {
    public function label(): string {
        return 'child';
    }
    public function viaSelf(): string {
        $f = self::label(...);
        return $f();
    }
    public function viaParent(): string {
        $f = parent::label(...);
        return $f();
    }
    public function viaStatic(): string {
        $f = static::label(...);
        return $f();
    }
}
$o = new ChildFcc();
echo $o->viaSelf(), "\n";
echo $o->viaParent(), "\n";
echo $o->viaStatic(), "\n";
echo (new ReflectionMethod(ParentFcc::class, 'viaSelf'))->invoke($o), "\n";
echo (new ReflectionMethod(ParentFcc::class, 'viaStatic'))->invoke($o), "\n";
try {
    ChildFcc::label(...);
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
child
parent
child
parent
child
Non-static method ChildFcc::label() cannot be called statically
