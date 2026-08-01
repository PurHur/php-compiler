--TEST--
Language: typed return with bare return; TypeError none returned (#16117/#26486, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

$fn = function (): int {
    return;
};

try {
    $fn();
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class C {
    public function f(): int {
        return;
    }
}

try {
    (new C())->f();
    echo "method no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: {closure}(): Return value must be of type int, none returned
TypeError: C::f(): Return value must be of type int, none returned
