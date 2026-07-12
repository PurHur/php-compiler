--TEST--
Language: parent::instanceMethod(...) first-class callable (#17655, zend_compile.c)
--FILE--
<?php
class ParentMethod {
    public function label(): string {
        return 'parent';
    }
}
class ChildMethod extends ParentMethod {
    public function label(): string {
        return 'child';
    }
    public function viaFcc(): string {
        $f = parent::label(...);
        return $f();
    }
}
echo (new ChildMethod())->viaFcc(), "\n";
--EXPECT--
parent
