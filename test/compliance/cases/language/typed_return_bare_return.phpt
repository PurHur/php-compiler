--TEST--
Language: typed return with bare return; fatals (#16117, Zend/zend_execute.c)
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
Error: A function with return type must return a value
Error: A function with return type must return a value
