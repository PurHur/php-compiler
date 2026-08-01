--TEST--
stdlib get_parent_class()/get_class() zero-arg — Deprecated + value under PROFILE=8.4 (#26369, Zend/zend_builtin_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $n, string $s): bool {
    echo 'DEP:', $s, "\n";

    return true;
});

class P {}
class C extends P {
    public function f() {
        return get_parent_class();
    }
}
echo (new C)->f(), "\n";

class D {
    public function g() {
        return get_class();
    }
}
echo (new D)->g(), "\n";

class Root {
    public function h() {
        var_export(get_parent_class());
        echo "\n";
    }
}
(new Root)->h();
--EXPECT--
DEP:Calling get_parent_class() without arguments is deprecated
P
DEP:Calling get_class() without arguments is deprecated
D
DEP:Calling get_parent_class() without arguments is deprecated
false
