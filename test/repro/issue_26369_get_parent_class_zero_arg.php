<?php
/**
 * Issue #26369 — zero-arg get_parent_class()/get_class() match Zend (Deprecated + value).
 *
 * php-src: Zend/zend_builtin_functions.c
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_26369_get_parent_class_zero_arg.php
 */
set_error_handler(static function (int $n, string $s): bool {
    echo 'DEP:', $s, "\n";

    return true;
});

class P
{
}

class C extends P
{
    public function f()
    {
        return get_parent_class();
    }
}

echo (new C())->f(), "\n";

class D
{
    public function g()
    {
        return get_class();
    }
}

echo (new D())->g(), "\n";
